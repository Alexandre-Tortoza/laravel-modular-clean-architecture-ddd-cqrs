#!/usr/bin/env bash
set -euo pipefail

php_image="${1:?Provide the PHP production image}"
nginx_image="${2:?Provide the Nginx production image}"
revision="${3:?Provide the checked-out source SHA}"
store="${4:-database}"
case "$store" in
    database|redis) ;;
    *) printf '%s\n' 'Choose the database or redis smoke store.' >&2; exit 1 ;;
esac
temporary_directory="$(mktemp -d)"
project="production-smoke-${RANDOM}-${RANDOM}"
compose=(docker compose --env-file "$temporary_directory/compose.env" -p "$project" -f compose.production.smoke.yaml)
if test "$store" = redis; then
    compose+=(--profile redis)
fi
services=(php nginx pgsql)
if test "$store" = redis; then
    services+=(redis)
fi
stage=environment
started=false

cleanup() {
    result=$?
    if test "$result" -ne 0; then
        printf 'Production smoke failed during %s.\n' "$stage" >&2
        if test "$started" = true; then "${compose[@]}" logs >&2 || true; fi
    fi
    if test "$started" = true; then
        "${compose[@]}" down --volumes --timeout 30 >/dev/null 2>&1 || true
    fi
    rm -rf "$temporary_directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
application_key="base64:$(openssl rand -base64 32)"
password="$(openssl rand -hex 24)"
export SMOKE_PHP_IMAGE="$php_image" SMOKE_NGINX_IMAGE="$nginx_image"
export SMOKE_DB_PASSWORD="$(openssl rand -hex 24)" SMOKE_DB_DATABASE="smoke_${RANDOM}_${RANDOM}"
export SMOKE_RUNTIME_ENV_FILE="$temporary_directory/runtime.env"
touch "$temporary_directory/compose.env"
cat >"$SMOKE_RUNTIME_ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$application_key
APP_URL=https://localhost
TRUSTED_PROXIES=
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=$SMOKE_DB_DATABASE
DB_USERNAME=smoke
DB_PASSWORD=$SMOKE_DB_PASSWORD
DB_URL=
DB_SSLMODE=disable
SESSION_DRIVER=$store
SESSION_SECURE_COOKIE=true
CACHE_STORE=$store
QUEUE_CONNECTION=$store
REDIS_HOST=redis
REDIS_PREFIX=$project-
REDIS_DB=0
REDIS_CACHE_DB=1
MAIL_MAILER=array
SMOKE_TEST_PASSWORD=$password
EOF

stage=compose-start
"${compose[@]}" config --quiet
started=true
"${compose[@]}" up --detach --no-build --wait --wait-timeout 90

stage=runtime-boundaries
for service in "${services[@]}"; do
    container="$("${compose[@]}" ps -q "$service")"
    docker inspect "$container" | python -c '
import json, sys
container = json.load(sys.stdin)[0]
assert not any(mount["Type"] == "bind" for mount in container["Mounts"])
assert container["Config"]["User"] not in ["", "0", "root"]
if container["Config"]["User"] != "nginx:nginx":
    assert not any(container["NetworkSettings"]["Ports"].values())
'
done
"${compose[@]}" exec -T php sh -ec '
    test ! -w /app/public/index.php
    test -w /app/bootstrap/cache
    test -w /app/storage/framework/views
    test ! -e /app/.env
    test ! -e /app/resources/js
    ! command -v node
    ! command -v npm
'

stage=migrations-and-fixture
"${compose[@]}" exec -T -e SMOKE_ACTION=initialize php php <tests/Docker/production-fixture.php >"$temporary_directory/fixture.json"
python - "$temporary_directory/fixture.json" <<'PY'
import json, pathlib, sys
fixture = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert fixture['driver'] == 'pgsql'
assert fixture['postgres_major'] == 16
assert fixture['users'] == 2 and fixture['teams'] == 2
assert fixture['personal_team_slug'] and fixture['outsider_team_slug']
PY

stage=selected-stores
if test "$store" = database; then
    test -z "$("${compose[@]}" ps --all --quiet redis)"
fi
"${compose[@]}" exec -T -e SMOKE_ACTION=drivers php php <tests/Docker/production-fixture.php >"$temporary_directory/drivers.json"
python - "$temporary_directory/drivers.json" "$store" <<'PYTHON'
import json, pathlib, sys
result = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert result == {'session': sys.argv[2], 'cache': sys.argv[2], 'queue': sys.argv[2], 'cache_roundtrip': True, 'queue_roundtrip': True}
PYTHON

stage=http-and-versioned-assets
port="$("${compose[@]}" port nginx 8080 | cut -d: -f2)"
base_url="http://localhost:$port"
cookie_jar="$temporary_directory/cookies"
curl --silent --fail --max-time 10 "$base_url/up" >"$temporary_directory/health"
grep -q 'Application up' "$temporary_directory/health"
curl --silent --fail --max-time 10 "$base_url/build/release.json" >"$temporary_directory/release.json"
"${compose[@]}" exec -T php cat /app/public/build/release.json >"$temporary_directory/php-release.json"
cmp "$temporary_directory/release.json" "$temporary_directory/php-release.json"
python - "$temporary_directory/release.json" "$revision" >"$temporary_directory/assets" <<'PY'
import json, pathlib, sys
release = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert release['source_revision'] == sys.argv[2]
assert 'manifest.json' in release['files']
for path, digest in release['files'].items():
    print(digest, path)
PY
while read -r expected_hash path; do
    curl --silent --fail --max-time 10 "$base_url/build/$path" >"$temporary_directory/asset"
    test "$(sha256sum "$temporary_directory/asset" | cut -d' ' -f1)" = "$expected_hash"
done <"$temporary_directory/assets"
curl --silent --fail --max-time 10 --cookie-jar "$cookie_jar" --dump-header "$temporary_directory/login-headers" "$base_url/login" >"$temporary_directory/login"
grep -q '/build/assets/' "$temporary_directory/login"
grep -Ei '^set-cookie: .*secure' "$temporary_directory/login-headers" | grep -qi 'httponly'

csrf_token() {
    python - "$cookie_jar" <<'PY'
import pathlib, sys, urllib.parse
for line in pathlib.Path(sys.argv[1]).read_text().splitlines():
    fields = line.split('\t')
    if len(fields) == 7 and fields[5] == 'XSRF-TOKEN':
        print(urllib.parse.unquote(fields[6]))
        break
else:
    raise SystemExit('Missing encrypted CSRF cookie.')
PY
}
request() {
    curl --silent --max-time 10 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --header 'Accept: application/json' --output "$temporary_directory/response.json" \
        --write-out '%{http_code}' "$@"
}

stage=authentication
test "$(request --data 'email=owner%40smoke.example.invalid&password=wrong-password' "$base_url/login")" = 419
test "$(request --header "X-XSRF-TOKEN: $(csrf_token)" --data 'email=owner%40smoke.example.invalid&password=wrong-password' "$base_url/login")" = 422
test "$(request --header "X-XSRF-TOKEN: $(csrf_token)" --data-urlencode 'email=owner@smoke.example.invalid' --data-urlencode "password=$password" "$base_url/login")" = 200
python - "$temporary_directory/response.json" <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text()) == {'two_factor': False}
PY

stage=inertia-and-teams
code="$(request --header 'X-Inertia: true' --header 'X-Inertia-Version: stale-smoke-release' \
    --dump-header "$temporary_directory/version-headers" "$base_url/settings/teams")"
test "$code" = 409
asset_version="$(awk -F ': *' 'tolower($1) == "x-inertia-version" { gsub("\r", "", $2); print $2 }' "$temporary_directory/version-headers")"
test -n "$asset_version"
test "$(request --header 'X-Inertia: true' --header "X-Inertia-Version: $asset_version" "$base_url/settings/teams")" = 200
python - "$temporary_directory/response.json" "$temporary_directory/fixture.json" <<'PY'
import json, pathlib, sys
page, fixture = [json.loads(pathlib.Path(path).read_text()) for path in sys.argv[1:]]
assert page['component'] == 'teams/Index'
assert page['props']['auth']['user']['id'] == fixture['owner_id']
assert len(page['props']['teams']) == 1
assert page['props']['teams'][0]['slug'] == fixture['personal_team_slug']
PY
outsider_slug="$(python -c 'import json,sys; print(json.load(open(sys.argv[1]))["outsider_team_slug"])' "$temporary_directory/fixture.json")"
test "$(request "$base_url/$outsider_slug/dashboard")" = 403
test "$(request --header "X-XSRF-TOKEN: $(csrf_token)" --data-urlencode 'name=Production Smoke Team' "$base_url/settings/teams")" = 302
test "$(request --header 'X-Inertia: true' --header "X-Inertia-Version: $asset_version" "$base_url/settings/teams/production-smoke-team")" = 200
python - "$temporary_directory/response.json" <<'PY'
import json, pathlib, sys
page = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert page['component'] == 'teams/Edit'
assert page['props']['team']['name'] == 'Production Smoke Team'
assert page['props']['members'][0]['role'] == 'owner'
PY
"${compose[@]}" exec -T -e SMOKE_ACTION=verify php php <tests/Docker/production-fixture.php >"$temporary_directory/verified.json"
python - "$temporary_directory/verified.json" <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text()) == {'users': 2, 'teams': 3, 'memberships': 3, 'persisted': True, 'sessions': True}
PY

stage=key-and-network-failure-detection
if "${compose[@]}" run --rm --no-deps -e APP_KEY= php php artisan about --no-interaction >"$temporary_directory/missing-key" 2>&1; then
    printf '%s\n' 'The production image unexpectedly accepted a missing APP_KEY.' >&2
    exit 1
fi
grep -q 'Inject a stable APP_KEY' "$temporary_directory/missing-key"
"${compose[@]}" exec -T php sh -ec '! test -w /app/config/app.php'
"${compose[@]}" stop --timeout 30 php
upstream_status="$(curl --silent --max-time 10 --output /dev/null --write-out '%{http_code}' "$base_url/up")"
case "$upstream_status" in
    502|504) ;;
    *) printf 'Expected an unavailable upstream, received HTTP %s.\n' "$upstream_status" >&2; exit 1 ;;
esac
"${compose[@]}" up --detach --no-build php
for attempt in {1..40}; do
    if curl --silent --fail --max-time 5 "$base_url/up" > /dev/null; then break; fi
    sleep 0.25
done
curl --silent --fail --max-time 5 "$base_url/up" >/dev/null
test "$(request --header 'X-Inertia: true' --header "X-Inertia-Version: $asset_version" "$base_url/settings/teams")" = 200
python - "$temporary_directory/response.json" "$temporary_directory/fixture.json" <<'PY'
import json, pathlib, sys
page, fixture = [json.loads(pathlib.Path(path).read_text()) for path in sys.argv[1:]]
assert page['props']['auth']['user']['id'] == fixture['owner_id']
assert len(page['props']['teams']) == 2
PY

stage=graceful-shutdown
"${compose[@]}" stop --timeout 30 "${services[@]}"
for service in "${services[@]}"; do
    test "$(docker inspect --format '{{.State.ExitCode}}' "$("${compose[@]}" ps --all -q "$service")")" = 0
done
stage=owned-resource-cleanup
"${compose[@]}" down --volumes --timeout 30
started=false
test -z "$(docker ps --all --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker volume ls --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker network ls --quiet --filter "label=com.docker.compose.project=$project")"
printf 'Production %s smoke passed: \n' "$store"
printf '%s\n' 'Validated: immutable non-root images, private PostgreSQL 16, migrations, secure session/CSRF login, Inertia, Teams isolation/write, versioned assets, APP_KEY/upstream failure detection and graceful cleanup.'
