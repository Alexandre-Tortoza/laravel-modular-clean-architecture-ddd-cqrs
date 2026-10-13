#!/usr/bin/env bash
set -euo pipefail
php_image="${1:?Provide the PHP production image}"
nginx_image="${2:?Provide the Nginx production image}"
temporary_directory="$(mktemp -d)"
project="runtime-smoke-${RANDOM}-${RANDOM}"
stage=environment
started=false
compose=(docker compose --env-file "$temporary_directory/compose.env" -p "$project" -f compose.production.smoke.yaml)
cleanup() {
    result=$?
    if test "$result" -ne 0; then
        printf 'Runtime safeguards failed during %s.\n' "$stage" >&2
        if test "$started" = true; then "${compose[@]}" logs >&2 || true; fi
    fi
    if test "$started" = true; then "${compose[@]}" down --volumes --timeout 30 >/dev/null 2>&1 || true; fi
    rm -rf "$temporary_directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
application_key="base64:$(openssl rand -base64 32)"
export SMOKE_PHP_IMAGE="$php_image" SMOKE_NGINX_IMAGE="$nginx_image"
export SMOKE_RUNTIME_ENV_FILE="$temporary_directory/runtime.env"
export SMOKE_DB_DATABASE="smoke_${RANDOM}_${RANDOM}" SMOKE_DB_PASSWORD="$(openssl rand -hex 24)"
touch "$temporary_directory/compose.env"
cat >"$SMOKE_RUNTIME_ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$application_key
APP_URL=https://localhost
SESSION_SECURE_COOKIE=true
SESSION_DRIVER=database
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=$SMOKE_DB_DATABASE
DB_USERNAME=smoke
DB_PASSWORD=$SMOKE_DB_PASSWORD
DB_SSLMODE=disable
DB_URL=
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=array
SMOKE_TEST_PASSWORD=$(openssl rand -hex 24)
EOF
stage=startup
started=true
"${compose[@]}" up --detach --no-build --wait --wait-timeout 90
"${compose[@]}" exec -T -e SMOKE_ACTION=initialize php php <tests/Docker/production-fixture.php >/dev/null
port="$("${compose[@]}" port nginx 8080 | cut -d: -f2)"
base_url="http://localhost:$port"
stage=healthy-readiness
curl --silent --fail --max-time 10 "$base_url/up" >/dev/null
curl --silent --fail --max-time 10 "$base_url/ready" >"$temporary_directory/readiness"
python - "$temporary_directory/readiness" <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text()) == {'status': 'ready'}
PY

stage=persistent-upload
"${compose[@]}" exec -T php php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$encrypted = $app->make('encrypter')->encryptString('retained-private-upload');
if (! Illuminate\Support\Facades\Storage::disk('local')->put('runtime-smoke.enc', $encrypted)) { exit(1); }
Illuminate\Support\Facades\Log::info('runtime-safeguards-log-smoke');
PHP
"${compose[@]}" exec -T php sh -ec '
    test -w /app/storage/app/private
    test ! -w /app/public/index.php
    test ! -w /app/config/app.php
    test "$(stat -c %a /app/storage/app/private)" = 750
    test "$(stat -c %a /app/storage/app/private/runtime-smoke.enc)" = 600
'
"${compose[@]}" logs >"$temporary_directory/logs"
grep -q runtime-safeguards-log-smoke "$temporary_directory/logs"
test "$(curl --silent --max-time 10 --output /dev/null --write-out '%{http_code}' "$base_url/storage/runtime-smoke.enc")" = 404

stage=dependency-outage
"${compose[@]}" stop --timeout 30 pgsql
test "$(curl --silent --max-time 10 --output /dev/null --write-out '%{http_code}' "$base_url/up")" = 200
test "$(curl --silent --max-time 10 --output "$temporary_directory/readiness" --write-out '%{http_code}' "$base_url/ready")" = 503
python - "$temporary_directory/readiness" <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text()) == {'status': 'unavailable'}
PY
"${compose[@]}" up --detach --no-build --wait --wait-timeout 90 pgsql

stage=immutable-recreate
"${compose[@]}" up --detach --no-build --force-recreate --wait --wait-timeout 90 php nginx
"${compose[@]}" exec -T php php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$encrypted = Illuminate\Support\Facades\Storage::disk('local')->get('runtime-smoke.enc');
if ($app->make('encrypter')->decryptString($encrypted) !== 'retained-private-upload'
    || Illuminate\Support\Facades\DB::table('users')->count() !== 2
    || Illuminate\Support\Facades\DB::table('teams')->count() !== 2) { exit(1); }
PHP
port="$("${compose[@]}" port nginx 8080 | cut -d: -f2)"
base_url="http://localhost:$port"
curl --silent --fail --max-time 10 "$base_url/ready" >/dev/null

stage=ephemeral-storage-refusal
if docker run --rm --network none --env-file "$SMOKE_RUNTIME_ENV_FILE" "$php_image" php artisan about --no-interaction >"$temporary_directory/refusal" 2>&1; then
    printf '%s\n' 'Production unexpectedly accepted ephemeral upload storage.' >&2
    exit 1
fi
grep -q 'Mount persistent upload storage' "$temporary_directory/refusal"

stage=secret-free-logs
"${compose[@]}" logs >>"$temporary_directory/logs"
! grep -Fq "$application_key" "$temporary_directory/logs"
! grep -Fq "$SMOKE_DB_PASSWORD" "$temporary_directory/logs"
! grep -Eq 'SQLSTATE|Stack trace|PDOException' "$temporary_directory/logs"

stage=graceful-cleanup
"${compose[@]}" stop --timeout 30 php nginx pgsql
for service in php nginx pgsql; do
    test "$(docker inspect --format '{{.State.ExitCode}}' "$("${compose[@]}" ps --all -q "$service")")" = 0
done
"${compose[@]}" down --volumes --timeout 30
started=false
test -z "$(docker ps --all --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker volume ls --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker network ls --quiet --filter "label=com.docker.compose.project=$project")"
printf '%s\n' 'Runtime safeguards passed: liveness/readiness, dependency outage, retained PostgreSQL/APP_KEY/private upload, minimal permissions, safe logs and graceful cleanup.'
