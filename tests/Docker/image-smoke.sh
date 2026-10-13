#!/usr/bin/env bash
set -euo pipefail

image="${1:-laravel-php:smoke}"
container="laravel-php-smoke-${RANDOM}"
temporary_directory="$(mktemp -d)"
stage=environment

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        printf 'PHP image smoke failed during %s.\n' "$stage" >&2
        if [ -f "$temporary_directory/cli-output" ]; then
            cat "$temporary_directory/cli-output" >&2
        fi
        if [ -f "$temporary_directory/fpm-logs" ]; then
            cat "$temporary_directory/fpm-logs" >&2
        fi
        if [ -f "$temporary_directory/slow-response" ]; then
            cat "$temporary_directory/slow-response" >&2
            docker logs "$container" >&2 2>/dev/null || true
        fi
    fi
    docker rm --force "$container" >/dev/null 2>&1 || true
    rm -rf "$temporary_directory"
}
trap cleanup EXIT

command -v cgi-fcgi >/dev/null
test "$(docker image inspect --format '{{.Config.User}}' "$image")" != root
test "$(docker image inspect --format '{{.Config.StopSignal}}' "$image")" = SIGQUIT

application_key="base64:$(openssl rand -base64 32)"
stage=cli-runtime
docker run --rm --network none --env "APP_KEY=$application_key" --env APP_URL=https://template.example.invalid "$image" sh -ec '
    test "$(id -u)" -ne 0
    test ! -w artisan
    test ! -w vendor/autoload.php
    test -w storage/framework/views
    test -w storage/framework/sessions
    test -w bootstrap/cache
    touch storage/framework/views/permission-smoke bootstrap/cache/permission-smoke
    test ! -e .env
    test ! -e auth.json
    test ! -e .git
    test ! -e public/hot
    test ! -e node_modules
    test ! -e vendor/bin/pest
    test ! -e vendor/bin/pint
    test ! -e vendor/laravel/sail
    test ! -e vendor/laravel/boost
    ! command -v composer
    ! command -v node
    ! command -v npm
    ! command -v gcc
    test -s public/build/manifest.json
    php -r '\''
        if (version_compare(PHP_VERSION, "8.4.1", "<") || extension_loaded("xdebug")) {
            exit(1);
        }
        foreach (["ctype", "curl", "dom", "fileinfo", "filter", "hash", "mbstring", "openssl", "PDO", "pdo_pgsql", "pcntl", "redis", "session", "tokenizer", "xml", "Zend OPcache"] as $extension) {
            if (! extension_loaded($extension)) {
                fwrite(STDERR, "Missing extension: {$extension}\n");
                exit(1);
            }
        }
        $installed = json_decode(file_get_contents("vendor/composer/installed.json"), true, flags: JSON_THROW_ON_ERROR);
        if ($installed["dev"] !== false || $installed["dev-package-names"] !== []) {
            exit(1);
        }
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! $app->isProduction() || config("app.debug") || config("logging.default") !== "stderr" || config("database.redis.client") !== "phpredis") {
            exit(1);
        }
        Illuminate\Support\Facades\Log::info("production-image-log-smoke");
    '\''
    php artisan route:list --except-vendor --no-interaction >/dev/null
' >"$temporary_directory/cli-output" 2>&1
grep -q production-image-log-smoke "$temporary_directory/cli-output"

cat >"$temporary_directory/slow.php" <<'PHP'
<?php
file_put_contents('/tmp/smoke-request-started', 'started');
$deadline = hrtime(true) + 2000000000;
while (hrtime(true) < $deadline) {
    usleep(10000);
}
echo 'graceful-request-complete';
PHP
chmod 0755 "$temporary_directory"
chmod 0644 "$temporary_directory/slow.php"

stage=fpm-start
docker run --detach --name "$container" \
    --cap-drop ALL --security-opt no-new-privileges \
    --publish 127.0.0.1::9000 \
    --mount "type=bind,source=$temporary_directory,target=/tmp/image-smoke,readonly" \
    --env "APP_KEY=$application_key" --env APP_URL=https://template.example.invalid --env SESSION_DRIVER=file \
    "$image" >/dev/null
port="$(docker port "$container" 9000/tcp | cut -d: -f2)"

request() {
    SCRIPT_FILENAME="${2:-/app/public/index.php}" \
        SCRIPT_NAME=/index.php REQUEST_METHOD=GET REQUEST_URI="$1" \
        SERVER_NAME=localhost SERVER_PORT=80 SERVER_PROTOCOL=HTTP/1.1 \
        HTTP_HOST=localhost REMOTE_ADDR=127.0.0.1 \
        cgi-fcgi -bind -connect "127.0.0.1:$port"
}

stage=health-request
for attempt in $(seq 1 40); do
    if request /up >"$temporary_directory/health" 2>/dev/null; then
        break
    fi
    sleep 0.25
done
grep -q 'Application up' "$temporary_directory/health"
! grep -Eq '^Status: [45]' "$temporary_directory/health"
stage=login-assets
request /login >"$temporary_directory/login"
! grep -Eq '^Status: [45]' "$temporary_directory/login"
grep -q '/build/assets/' "$temporary_directory/login"
grep -qi 'set-cookie:.*secure' "$temporary_directory/login"
stage=fpm-access-logs
docker logs "$container" >"$temporary_directory/fpm-logs" 2>&1
grep -q ' GET 200 ' "$temporary_directory/fpm-logs"

stage=graceful-shutdown
request /slow /tmp/image-smoke/slow.php >"$temporary_directory/slow-response" &
request_pid=$!
for attempt in $(seq 1 40); do
    if docker exec "$container" test -f /tmp/smoke-request-started; then
        break
    fi
    sleep 0.1
done
docker exec "$container" test -f /tmp/smoke-request-started
stage=graceful-stop
docker stop --time 15 "$container" >/dev/null
stage=graceful-response
wait "$request_pid"
grep -q graceful-request-complete "$temporary_directory/slow-response"
test "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = 0
printf '%s\n' 'PHP image smoke passed: non-root, production dependencies, assets, FPM, logs, graceful shutdown.'
