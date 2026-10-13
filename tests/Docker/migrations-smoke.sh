#!/usr/bin/env bash
set -euo pipefail

php_image="${1:?Provide the actual production PHP image}"
revision="${2:?Provide its full source SHA}"
temporary_directory="$(mktemp -d)"
project="migrations-smoke-${RANDOM}-${RANDOM}"
stage=environment
started=false
compose=(docker compose --profile release -p "$project" -f compose.production.smoke.yaml -f compose.production.migrations.yaml -f "$temporary_directory/migration-network.yaml")

cleanup() {
    result=$?
    if test "$result" -ne 0; then printf 'Controlled migrations smoke failed during %s.\n' "$stage" >&2; fi
    if test "$started" = true; then "${compose[@]}" down --volumes --timeout 30 >/dev/null 2>&1 || true; fi
    docker image rm "$project:failure" "$project:expanded" "$project:changed" >/dev/null 2>&1 || true
    rm -rf "$temporary_directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
export SOURCE_REVISION="$revision" PHP_IMAGE="$php_image"
export SMOKE_PHP_IMAGE="$php_image" SMOKE_NGINX_IMAGE="$php_image"
export SMOKE_DB_DATABASE="smoke_migrations_${RANDOM}_${RANDOM}" SMOKE_DB_PASSWORD="$(openssl rand -hex 24)"
export RUNTIME_ENV_FILE="$temporary_directory/runtime.env" SMOKE_RUNTIME_ENV_FILE="$temporary_directory/runtime.env"
cat >"$temporary_directory/migration-network.yaml" <<'EOF'
services:
    migration:
        networks: !override [database]
EOF
cat >"$RUNTIME_ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://localhost
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=$SMOKE_DB_DATABASE
DB_USERNAME=smoke
DB_PASSWORD=$SMOKE_DB_PASSWORD
DB_SSLMODE=disable
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=array
SMOKE_TEST_PASSWORD=$(openssl rand -hex 24)
EOF

sql() { "${compose[@]}" exec -T pgsql psql -U smoke -d "$SMOKE_DB_DATABASE" -v ON_ERROR_STOP=1 -Atc "$1"; }
migrate() { "${compose[@]}" run --rm --no-deps -T "$@" migration; }
consumer() { "${compose[@]}" run --rm --no-deps -T -e "SMOKE_MIGRATION_ACTION=$1" php php <tests/Docker/migration-consumers.php; }

stage=test-only-migration-candidates
# These derived images inject representative migrations only in disposable CI.
# They are never published, and application source is never mounted into runtime.
for candidate in failure expanded; do
    if test "$candidate" = failure; then fixture=2030_01_01_000002_fail_release_probe.php; else fixture=2030_01_01_000001_expand_release_probe.php; fi
    docker build --build-arg "BASE_IMAGE=$php_image" --build-arg "FIXTURE=$fixture" --tag "$project:$candidate" --file - tests/Docker/migration-fixtures <<'EOF'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
ARG FIXTURE
COPY --chmod=0644 ${FIXTURE} /app/database/migrations/${FIXTURE}
EOF
done
docker build --build-arg "BASE_IMAGE=$php_image" --tag "$project:changed" --file - "$temporary_directory" <<'EOF'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
USER root
RUN php -r '$path="/app/public/build/release.json"; $manifest=json_decode(file_get_contents($path),true); $manifest["test_only_artifact_mutation"]=true; file_put_contents($path,json_encode($manifest));'
USER www-data:www-data
EOF

stage=private-database-start
"${compose[@]}" config --quiet
started=true
"${compose[@]}" up --detach --no-build --wait --wait-timeout 90 pgsql
test "$(sql "SELECT to_regclass('public.users') IS NULL")" = t

stage=failed-migration-does-not-complete-release
export PHP_IMAGE="$project:failure"
if migrate >"$temporary_directory/failure.log" 2>&1; then
    printf '%s\n' 'The failing migration unexpectedly completed.' >&2
    exit 1
fi
grep -q 'Release migrations failed' "$temporary_directory/failure.log"
! grep -q 'migration-secret-canary' "$temporary_directory/failure.log"
test "$(sql 'SELECT count(*) FROM _template_release_migrations')" = 0
test "$(sql 'SELECT count(*) FROM users')" = 0
test "$(sql 'SELECT count(*) FROM pg_locks WHERE locktype = '\''advisory'\'' AND classid = 1280131922 AND objid = 1 AND granted')" = 0
consumer initialize
consumer legacy

stage=explicit-lock-timeout
sql 'SELECT pg_advisory_lock(1280131922, 1); SELECT pg_sleep(8)' >"$temporary_directory/holder.log" &
holder=$!
for attempt in {1..40}; do
    if test "$(sql 'SELECT count(*) FROM pg_locks WHERE locktype = '\''advisory'\'' AND classid = 1280131922 AND objid = 1 AND granted')" = 1; then break; fi
    sleep 0.1
done
test "$(sql 'SELECT count(*) FROM pg_locks WHERE locktype = '\''advisory'\'' AND classid = 1280131922 AND objid = 1 AND granted')" = 1
export PHP_IMAGE="$project:expanded"
if migrate -e RELEASE_MIGRATION_LOCK_TIMEOUT=0 >"$temporary_directory/timeout.log" 2>&1; then
    printf '%s\n' 'Lock contention unexpectedly allowed the release to proceed.' >&2
    exit 1
fi
grep -q 'Migration lock wait timed out' "$temporary_directory/timeout.log"

stage=concurrent-releases-wait-and-apply-once
migrate -e RELEASE_MIGRATION_LOCK_TIMEOUT=30 >"$temporary_directory/first.log" 2>&1 &
first=$!
migrate -e RELEASE_MIGRATION_LOCK_TIMEOUT=30 >"$temporary_directory/second.log" 2>&1 &
second=$!
test "$(sql 'SELECT count(*) FROM _template_release_migrations')" = 0
wait "$holder"
wait "$first"
wait "$second"
test "$(grep -h -c '^Migrations completed for release ' "$temporary_directory/first.log" "$temporary_directory/second.log" | awk '{sum += $1} END {print sum}')" = 1
test "$(grep -h -c '^Migrations already completed for this release.' "$temporary_directory/first.log" "$temporary_directory/second.log" | awk '{sum += $1} END {print sum}')" = 1
test "$(sql 'SELECT count(*) FROM _template_release_migrations')" = 1
test "$(sql "SELECT count(*) FROM migrations WHERE migration = '2030_01_01_000001_expand_release_probe'")" = 1

stage=retained-and-candidate-schema-compatibility
consumer legacy
"${compose[@]}" run --rm --no-deps -T -e SMOKE_MIGRATION_ACTION=expanded migration php <tests/Docker/migration-consumers.php
consumer legacy
batch_before="$(sql 'SELECT count(*), max(batch) FROM migrations')"
migrate >"$temporary_directory/repeat.log" 2>&1
grep -q '^Migrations already completed for this release.' "$temporary_directory/repeat.log"
test "$(sql 'SELECT count(*), max(batch) FROM migrations')" = "$batch_before"
test "$(sql 'SELECT count(*) FROM users')" = 1
test "$(sql 'SELECT count(*) FROM teams')" = 1
test "$(sql 'SELECT count(*) FROM _template_release_migrations')" = 1
stage=immutable-revision-cannot-change-artifact
export PHP_IMAGE="$project:changed"
if migrate >"$temporary_directory/changed.log" 2>&1; then
    printf '%s\n' 'A completed revision unexpectedly accepted a different artifact.' >&2
    exit 1
fi
grep -q 'A completed release revision cannot be reused with a different artifact.' "$temporary_directory/changed.log"
test "$(sql 'SELECT count(*), max(batch) FROM migrations')" = "$batch_before"
stage=owned-resource-cleanup
"${compose[@]}" down --volumes --timeout 30
started=false
test -z "$(docker ps --all --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker volume ls --quiet --filter "label=com.docker.compose.project=$project")"
printf '%s\n' 'Controlled release migrations passed: failure propagation, lock timeout/wait, serialized concurrent attempts, once-per-release receipt, retry, additive schema and retained authentication/team compatibility.'
