import argparse
import concurrent.futures
import hashlib
import http.cookiejar
import json
import math
import pathlib
import statistics
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, url):
        return None


class Client:
    def __init__(self, base_url):
        if urllib.parse.urlparse(base_url).hostname not in ["localhost", "127.0.0.1"]:
            raise ValueError("Only the disposable loopback service is allowed.")
        self.base_url = base_url
        # Like curl's localhost exception, accept secure cookies only for this loopback fixture.
        self.cookies = http.cookiejar.CookieJar(
            http.cookiejar.DefaultCookiePolicy(secure_protocols=("https", "wss", "http"))
        )
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect()
        )

    def request(self, path, data=None, headers=None):
        request = urllib.request.Request(
            self.base_url + path,
            data=urllib.parse.urlencode(data).encode() if data is not None else None,
            headers={"Accept": "application/json", **(headers or {})},
        )
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def csrf(self):
        return urllib.parse.unquote(next(cookie.value for cookie in self.cookies if cookie.name == "XSRF-TOKEN"))

    def login(self, email, password):
        assert self.request("/login")[0] == 200
        credentials = {"email": email, "password": password}
        assert self.request("/login", credentials)[0] == 419
        assert self.request("/login", credentials, {"X-XSRF-TOKEN": self.csrf()})[0] == 200


def latency_summary(samples, elapsed):
    assert samples and elapsed > 0
    ordered = sorted(samples)
    return {
        "requests": len(samples),
        "elapsed_seconds": elapsed,
        "requests_per_second": len(samples) / elapsed,
        "p50_ms": ordered[math.ceil(len(ordered) * 0.50) - 1],
        "p95_ms": ordered[math.ceil(len(ordered) * 0.95) - 1],
        "mean_ms": statistics.mean(samples),
        "latency_samples_ms": samples,
    }


def identity(client, version, expected_id, expected_slugs, current_slug):
    status, _, body = client.request("/settings/teams", headers={"X-Inertia": "true", "X-Inertia-Version": version})
    if expected_id is None:
        assert status == 401 and json.loads(body)["message"] == "Unauthenticated."
        return
    page = json.loads(body)
    assert status == 200 and page["component"] == "teams/Index"
    assert page["props"]["auth"]["user"]["id"] == expected_id
    assert sorted(team["slug"] for team in page["props"]["teams"]) == sorted(expected_slugs)
    assert page["props"]["currentTeam"]["slug"] == current_slug
    assert page["props"]["auth"]["user"]["current_team_id"] == page["props"]["currentTeam"]["id"]


def load(workers, requests, task):
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
        samples = list(executor.map(task, range(requests)))
    return latency_summary(samples, time.perf_counter() - start)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--fixture", type=pathlib.Path, required=True)
    parser.add_argument("--password-file", type=pathlib.Path, required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--requests", type=int, default=256)
    parser.add_argument("--round", type=int, required=True)
    parser.add_argument("--initialize", action="store_true")
    parser.add_argument("--output", type=pathlib.Path, required=True)
    args = parser.parse_args()
    assert 32 <= args.requests <= 4096 and 1 <= args.round <= 3
    fixture = json.loads(args.fixture.read_text())
    password = args.password_file.read_text().strip()
    owner, outsider, guest = [Client(args.url) for _ in range(3)]
    owner.login("owner@smoke.example.invalid", password)
    outsider.login("outsider@smoke.example.invalid", password)
    status, _, release_body = guest.request("/build/release.json")
    release = json.loads(release_body)
    assert status == 200 and release["source_revision"] == args.revision
    status, _, manifest = guest.request("/build/manifest.json")
    assert status == 200 and hashlib.sha256(manifest).hexdigest() == release["files"]["manifest.json"]
    status, version_headers, _ = owner.request("/settings/teams", headers={"X-Inertia": "true", "X-Inertia-Version": "stale-benchmark-version"})
    assert status == 409
    version = version_headers["X-Inertia-Version"]
    outsider_status, _, outsider_body = outsider.request("/settings/teams", headers={"X-Inertia": "true", "X-Inertia-Version": version})
    assert outsider_status == 200
    outsider_id = json.loads(outsider_body)["props"]["auth"]["user"]["id"]
    assert outsider_id != fixture["owner_id"]
    assert owner.request("/" + fixture["outsider_team_slug"] + "/dashboard")[0] == 403
    assert outsider.request("/" + fixture["personal_team_slug"] + "/dashboard")[0] == 403
    if args.initialize:
        assert owner.request(
            "/settings/teams", {"name": "Production Smoke Team"}, {"X-XSRF-TOKEN": owner.csrf()}
        )[0] == 302
    expected = [
        (owner, fixture["owner_id"], [fixture["personal_team_slug"], "production-smoke-team"], "production-smoke-team"),
        (outsider, outsider_id, [fixture["outsider_team_slug"]], fixture["outsider_team_slug"]),
        (guest, None, [], None),
    ]
    for client, identifier, slugs, current_slug in expected:
        identity(client, version, identifier, slugs, current_slug)
    assert outsider.request("/settings/teams", {"name": "Rejected Cross Session"}, {"X-XSRF-TOKEN": owner.csrf()})[0] == 419

    def up(index):
        start = time.perf_counter()
        status, _, body = guest.request("/up")
        assert status == 200 and json.loads(body) == {"status": "up"}
        return (time.perf_counter() - start) * 1000

    def teams(index):
        start = time.perf_counter()
        client, identifier, slugs, current_slug = expected[index % 3]
        identity(client, version, identifier, slugs, current_slug)
        return (time.perf_counter() - start) * 1000

    for index in range(32):
        up(index)
        teams(index)
    workloads = []
    for workers in [1, 8]:
        for name, task in [("up-json", up), ("teams-owner-outsider-guest", teams)]:
            workloads.append({"endpoint_mix": name, "concurrency": workers, **load(workers, args.requests, task)})
    for client, identifier, slugs, current_slug in expected:
        identity(client, version, identifier, slugs, current_slug)
    args.output.write_text(json.dumps({
        "round": args.round, "source_revision": args.revision, "workloads": workloads,
        "authentication_csrf_teams_inertia_and_cross_user_isolation": "passed",
        "http_client": "Python urllib; new HTTP connection per request; loopback only",
    }, indent=2) + "\n")


if __name__ == "__main__":
    main()
