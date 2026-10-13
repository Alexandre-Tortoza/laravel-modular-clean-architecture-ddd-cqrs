#!/usr/bin/env bash
set -euo pipefail
if test "$#" -ne 6; then
    printf '%s\n' 'Provide FPM, Nginx, Octane and Octane proxy images, source SHA and an output directory.' >&2
    exit 2
fi
fpm_image="$1"
nginx_image="$2"
octane_image="$3"
octane_proxy_image="$4"
revision="$5"
output="$6"
requests="${BENCH_REQUESTS:-256}"
if ! [[ "$revision" =~ ^[a-f0-9]{40}$ && "$requests" =~ ^[0-9]+$ ]] || test "$requests" -lt 32 || test "$requests" -gt 4096 || test -e "$output"; then
    printf '%s\n' 'Use a full source SHA, 32-4096 requests and a new output directory.' >&2
    exit 2
fi
mkdir "$output"
temporary_directory="$(mktemp -d)"
project="runtime-benchmark-${RANDOM}-${RANDOM}"
compose=(docker compose --env-file "$temporary_directory/compose.env" -p "$project" -f compose.production.smoke.yaml -f tests/Docker/runtime-benchmark.compose.yaml)
stage=environment
started=false
cleanup() {
    result=$?
    if test "$result" -ne 0; then
        printf 'Runtime benchmark failed during %s.\n' "$stage" >&2
        if test "$started" = true; then "${compose[@]}" logs >"$output/failure.log" 2>&1 || true; fi
    fi
    if test "$started" = true; then "${compose[@]}" down --volumes --timeout 30 >/dev/null 2>&1 || true; fi
    rm -rf "$temporary_directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
application_key="base64:$(openssl rand -base64 32)"
password="$(openssl rand -hex 24)"
printf '%s' "$password" >"$temporary_directory/password"
export SMOKE_DB_PASSWORD="$(openssl rand -hex 24)" SMOKE_DB_DATABASE="smoke_${RANDOM}_${RANDOM}"
export SMOKE_RUNTIME_ENV_FILE="$temporary_directory/runtime.env"
touch "$temporary_directory/compose.env"
cat >"$SMOKE_RUNTIME_ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$application_key
APP_URL=https://localhost
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=$SMOKE_DB_DATABASE
DB_USERNAME=smoke
DB_PASSWORD=$SMOKE_DB_PASSWORD
DB_URL=
DB_SSLMODE=disable
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=array
SMOKE_TEST_PASSWORD=$password
EOF
for runtime in fpm octane; do
    stage="$runtime-startup"
    if test "$runtime" = fpm; then
        export SMOKE_PHP_IMAGE="$fpm_image" SMOKE_NGINX_IMAGE="$nginx_image"
    else
        export SMOKE_PHP_IMAGE="$octane_image" SMOKE_NGINX_IMAGE="$octane_proxy_image"
    fi
    for image in "$SMOKE_PHP_IMAGE" "$SMOKE_NGINX_IMAGE"; do
        test "$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")" = "$revision"
    done
    "${compose[@]}" config --quiet
    startup_begin="$(python -c 'import time; print(time.perf_counter())')"
    started=true
    "${compose[@]}" up --detach --no-build --wait --wait-timeout 90
    port="$("${compose[@]}" port nginx 8080 | cut -d: -f2)"
    base_url="http://localhost:$port"
    for attempt in {1..120}; do
        if curl --silent --fail --max-time 2 "$base_url/up" > /dev/null; then break; fi
        sleep 0.25
    done
    curl --silent --fail --max-time 2 "$base_url/up" > /dev/null
    python - "$startup_begin" >"$output/$runtime-boot.json" <<'PY'
import json,sys,time
print(json.dumps({"compose_start_to_first_up_seconds":time.perf_counter()-float(sys.argv[1])}))
PY
    docker inspect "$("${compose[@]}" ps -q php)" "$("${compose[@]}" ps -q nginx)" "$("${compose[@]}" ps -q pgsql)" >"$temporary_directory/containers.json"
    python - "$temporary_directory/containers.json" "$output/$runtime-limits.json" <<'PY'
import json,pathlib,sys
containers=json.loads(pathlib.Path(sys.argv[1]).read_text())
result=[]
for container,(cpus,memory) in zip(containers,[(2,512),(0.5,128),(1,256)],strict=True):
    assert container["HostConfig"]["NanoCpus"]==cpus*1000000000
    assert container["HostConfig"]["Memory"]==memory*1024*1024
    assert not any(mount["Type"]=="bind" for mount in container["Mounts"])
    assert container["Config"]["User"] not in ["","0","root"]
    result.append({"service":container["Config"]["Labels"]["com.docker.compose.service"],"cpus":cpus,"memory_mib":memory,"image":container["Config"]["Image"]})
pathlib.Path(sys.argv[2]).write_text(json.dumps(result,indent=2)+"\n")
PY
    "${compose[@]}" exec -T php php -r 'echo PHP_VERSION.PHP_EOL;' >"$output/$runtime-php-version.txt"
    grep -qx '8.5.10' "$output/$runtime-php-version.txt"
    "${compose[@]}" exec -T php php --modules >"$output/$runtime-extensions.txt"
    "${compose[@]}" exec -T php cat /app/composer.lock >"$output/$runtime-composer.lock"
    stage="$runtime-identical-fixture"
    "${compose[@]}" exec -T -e SMOKE_ACTION=initialize php php <tests/Docker/production-fixture.php >"$temporary_directory/fixture.json"
    python - "$temporary_directory/fixture.json" <<'PY'
import json,pathlib,sys
fixture=json.loads(pathlib.Path(sys.argv[1]).read_text())
assert fixture["users"]==2 and fixture["teams"]==2 and fixture["postgres_major"]==16
PY
    for round in 1 2 3; do
        stage="$runtime-load-round-$round"
        initialize=()
        if test "$round" = 1; then initialize=(--initialize); fi
        python tests/Docker/runtime-benchmark-client.py --url "$base_url" --fixture "$temporary_directory/fixture.json" \
            --password-file "$temporary_directory/password" --revision "$revision" --requests "$requests" --round "$round" \
            "${initialize[@]}" --output "$output/$runtime-round-$round.json"
        for service in php nginx pgsql; do
            "${compose[@]}" exec -T "$service" sh -ec 'cat /sys/fs/cgroup/memory.current; cat /sys/fs/cgroup/memory.peak' >"$output/$runtime-$service-memory-round-$round.txt"
        done
    done
    "${compose[@]}" exec -T -e SMOKE_ACTION=verify php php <tests/Docker/production-fixture.php >"$output/$runtime-persistence.json"
    python - "$output/$runtime-persistence.json" <<'PY'
import json,pathlib,sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text())=={"users":2,"teams":3,"memberships":3,"persisted":True,"sessions":True}
PY
    stage="$runtime-cleanup"
    "${compose[@]}" down --volumes --timeout 30
    started=false
    test -z "$(docker ps --all --quiet --filter "label=com.docker.compose.project=$project")"
    test -z "$(docker volume ls --quiet --filter "label=com.docker.compose.project=$project")"
    test -z "$(docker network ls --quiet --filter "label=com.docker.compose.project=$project")"
done
python - "$output" "$revision" <<'PY'
import csv,json,pathlib,platform,statistics,sys
root=pathlib.Path(sys.argv[1])
result={"source_revision":sys.argv[2],"client_platform":platform.platform(),"default_runtime":"PHP-FPM","decision":"Keep PHP-FPM; benchmark evidence alone does not authorize changing production.","limits":"Same PHP8.5.10, CPU/memory quotas, 2 workers/max500 requests, isolated identical PostgreSQL16 data; three rounds per runtime at concurrency1/8. FPM runs first; shared GitHub runner/OS noise and different SAPI/extensions/protocols limit conclusions. New connection per urllib request; not a keep-alive or capacity sizing study.","persistent_state_risks":"Authentication, guest state, current team and CSRF are checked under alternating concurrent users and after every round. This does not prove absence of every singleton/static/memory leak; Octane requires auditing long-lived bindings, reloads and ongoing memory monitoring.","operational_cost":"Experimental-only Octane dependencies, FrankenPHP ZTS/Caddy, worker state/restarts, HTTP upstream and separate image maintenance. No production runtime/dependency switch."}
for runtime in ["fpm","octane"]:
    result[runtime]={"boot":json.loads((root/f"{runtime}-boot.json").read_text()),"rounds":[json.loads((root/f"{runtime}-round-{i}.json").read_text()) for i in [1,2,3]]}
    result[runtime]["memory"]=[]
    for round in [1,2,3]:
        for service in ["php","nginx","pgsql"]:
            current,peak=map(int,(root/f"{runtime}-{service}-memory-round-{round}.txt").read_text().splitlines())
            result[runtime]["memory"].append({"round":round,"service":service,"current_bytes":current,"cgroup_peak_since_boot_bytes":peak})
(root/"results.json").write_text(json.dumps(result,indent=2)+"\n")
with (root/"summary.csv").open("w") as file:
    writer=csv.writer(file)
    writer.writerow(["runtime","endpoint_mix","concurrency","median_requests_per_second","median_p50_ms","worst_round_p95_ms"])
    for runtime in ["fpm","octane"]:
        for index in range(4):
            samples=[round["workloads"][index] for round in result[runtime]["rounds"]]
            row=[runtime,samples[0]["endpoint_mix"],samples[0]["concurrency"],statistics.median(sample["requests_per_second"] for sample in samples),statistics.median(sample["p50_ms"] for sample in samples),max(sample["p95_ms"] for sample in samples)]
            writer.writerow(row)
            print(row)
print("Runtime benchmark completed with real metrics and isolation/persistence checks; PHP-FPM remains default.")
PY
