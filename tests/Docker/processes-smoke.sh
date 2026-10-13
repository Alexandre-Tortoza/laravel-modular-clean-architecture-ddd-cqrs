#!/usr/bin/env bash
set -euo pipefail
image="${1:?Provide the PHP production image}"
temporary_directory="$(mktemp -d)"
project="process-smoke-${RANDOM}-${RANDOM}"
volume="${project}-storage"
upload_volume="${project}-uploads"
cleanup() {
    result=$?
    if test "$result" -ne 0; then
        docker compose -p "$project" -f compose.production.processes.yaml -f "$temporary_directory/override.yaml" logs >&2 || true
    fi
    docker compose -p "$project" -f compose.production.processes.yaml -f "$temporary_directory/override.yaml" down --volumes --timeout 90 >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    docker volume rm "$upload_volume" >/dev/null 2>&1 || true
    rm -rf "$temporary_directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
cat >"$temporary_directory/runtime.env" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://template.example.invalid
SESSION_SECURE_COOKIE=true
SESSION_DRIVER=file
DB_CONNECTION=sqlite
DB_DATABASE=/app/storage/runtime-testing.sqlite
DB_URL=
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=array
REDIS_HOST=127.0.0.1
REDIS_PORT=1
LOG_CHANNEL=stderr
EOF
export PHP_IMAGE="$image" RUNTIME_ENV_FILE="$temporary_directory/runtime.env"
cat >"$temporary_directory/override.yaml" <<EOF
services:
    worker:
        volumes: ['runtime-storage:/app/storage']
    scheduler:
        volumes: ['runtime-storage:/app/storage']
volumes:
    runtime-storage:
        name: '$volume'
    app-storage:
        name: '$upload_volume'
EOF
docker volume create "$volume" >/dev/null
docker volume create "$upload_volume" >/dev/null
# Only the disposable test volume is migrated. This is never a production startup action.
run_fixture() {
    docker run --rm --network none --env-file "$RUNTIME_ENV_FILE" \
        --volume "$volume:/app/storage" \
        --volume "$upload_volume:/app/storage/app" \
        --mount "type=bind,source=$PWD/tests/Docker/queue-runtime.php,target=/app/tests/Docker/queue-runtime.php,readonly" \
        "$image" sh -ec 'touch /app/storage/runtime-testing.sqlite; php tests/Docker/queue-runtime.php "$@"' sh "$@"
}
run_fixture >"$temporary_directory/result.json"
python - "$temporary_directory/result.json" <<'PY'
import json
import sys
with open(sys.argv[1]) as fixture:
    result = json.load(fixture)
assert result == dict(driver='database', before_rollback=0, after_rollback=0,
                     before_commit=0, after_commit=1, worker_exit=0,
                     remaining_jobs=0, failed_jobs=0, mail_count=1), result
PY
compose=(docker compose -p "$project" -f compose.production.processes.yaml -f "$temporary_directory/override.yaml")
wait_for_processes() {
    for service in worker scheduler; do
        container="$("${compose[@]}" ps -q "$service")"
        test -n "$container"
        command=queue:work
        if test "$service" = scheduler; then command=schedule:work; fi
        ready=false
        for attempt in {1..30}; do
            if docker exec "$container" php -r '
                foreach (glob("/proc/[0-9]*/cmdline") as $path) {
                    if ($path !== "/proc/".getmypid()."/cmdline" && str_contains((string) @file_get_contents($path), "artisan\0".$argv[1]."\0")) { exit(0); }
                }
                exit(1);
            ' "$command"; then
                ready=true
                break
            fi
            sleep 1
        done
        test "$ready" = true
        test "$(docker inspect --format '{{.State.Running}}' "$container")" = true
        test "$(docker inspect --format '{{.Config.User}}' "$container")" = www-data:www-data
    done
}
"${compose[@]}" up --detach --no-build worker scheduler
wait_for_processes
test "$("${compose[@]}" ps -q scheduler | wc -l)" = 1
"${compose[@]}" exec -T scheduler php artisan schedule:list --no-ansi | grep -q 'Delete expired team invitations'
# Recreate processes as an immutable-image deploy would; retained database and queue stay intact.
"${compose[@]}" stop --timeout 90 worker
run_fixture enqueue >"$temporary_directory/pending.json"
python - "$temporary_directory/pending.json" <<'PY'
import json
import sys
with open(sys.argv[1]) as fixture:
    assert json.load(fixture) == {'pending_jobs': 1}
PY
"${compose[@]}" up --detach --no-build --force-recreate worker scheduler
wait_for_processes
drained=false
for attempt in {1..30}; do
    if "${compose[@]}" exec -T worker php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        exit(Illuminate\Support\Facades\DB::table("jobs")->count() === 0 && Illuminate\Support\Facades\DB::table("failed_jobs")->count() === 0 ? 0 : 1);
    '; then
        drained=true
        break
    fi
    sleep 1
done
test "$drained" = true
"${compose[@]}" stop --timeout 90 worker scheduler
for service in worker scheduler; do
    container="$("${compose[@]}" ps --all -q "$service")"
    test "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = 0
done
printf '%s\n' 'Process smoke passed: database queue afterCommit/rollback/delivery, dedicated non-root worker, one scheduler, restart and graceful exit without Redis.'
