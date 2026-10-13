#!/usr/bin/env bash
set -euo pipefail

php_image="${1:-laravel-php:smoke}"
nginx_image="${2:-laravel-nginx:smoke}"
network="laravel-nginx-smoke-${RANDOM}"
php_container="$network-php"
nginx_container="$network-nginx"
temporary_directory="$(mktemp -d)"
stage=environment

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        printf 'Nginx smoke failed during %s.\n' "$stage" >&2
        docker logs "$nginx_container" >&2 || true
        docker logs "$php_container" >&2 || true
    fi
    docker rm --force "$nginx_container" "$php_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    rm -rf "$temporary_directory"
}
trap cleanup EXIT

docker run --rm --network none --entrypoint sh "$nginx_image" -ec '
    test "$(id -u)" -ne 0
    test ! -w /app/public/index.php
    test ! -w /etc/nginx/nginx.conf
    test -w /tmp/nginx
    test ! -e /app/artisan
    test ! -e /app/vendor
    test ! -e /app/config
    test ! -e /app/.env
    ! command -v php
    ! command -v node
    test -s /app/public/build/manifest.json
'

stage=runtime-start
mkdir -p "$temporary_directory/fixtures/.git" "$temporary_directory/fixtures/nested"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/.env"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/.git/config"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/auth.json"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/private.pem"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/database.sqlite"
printf 'NGINX_SECRET_CANARY\n' >"$temporary_directory/fixtures/nested/.hidden"
printf '<?php echo "ARBITRARY_PHP_EXECUTED";\n' >"$temporary_directory/fixtures/evil.php"
cp "$temporary_directory/fixtures/evil.php" "$temporary_directory/fixtures/evil.PHP"
cp "$temporary_directory/fixtures/evil.php" "$temporary_directory/fixtures/evil.phtml"
ln -s /etc/passwd "$temporary_directory/fixtures/passwd-link"
chmod 0755 "$temporary_directory"
find "$temporary_directory/fixtures" -type d -exec chmod 0755 {} +
find "$temporary_directory/fixtures" -type f -exec chmod 0644 {} +

docker network create "$network" >/dev/null
application_key="base64:$(openssl rand -base64 32)"
docker run --detach --name "$php_container" --network "$network" --network-alias php \
    --cap-drop ALL --security-opt no-new-privileges \
    --env "APP_KEY=$application_key" --env SESSION_DRIVER=file \
    "$php_image" >/dev/null
docker run --detach --name "$nginx_container" --network "$network" \
    --cap-drop ALL --security-opt no-new-privileges \
    --publish 127.0.0.1::8080 \
    --mount "type=bind,source=$temporary_directory/fixtures,target=/app/public/security-smoke,readonly" \
    "$nginx_image" >/dev/null
port="$(docker port "$nginx_container" 8080/tcp | cut -d: -f2)"
base_url="http://127.0.0.1:$port"

stage=health
for attempt in $(seq 1 40); do
    if curl --silent --fail --max-time 5 "$base_url/up" >"$temporary_directory/health"; then
        break
    fi
    sleep 0.25
done
grep -q 'Application up' "$temporary_directory/health"
curl --silent --fail --max-time 10 "$base_url/login" >"$temporary_directory/login"
grep -q '/build/assets/' "$temporary_directory/login"
curl --silent --fail --max-time 10 "$base_url/index.php" >"$temporary_directory/front-controller"
! grep -q '<?php' "$temporary_directory/front-controller"

stage=security-boundary
for path in \
    /.env /.git/config /composer.json /composer.lock /artisan /vendor/autoload.php \
    /config/app.php /index.php/extra /INDEX.PHP /evil.php \
    /security-smoke/.env /security-smoke/%2eenv /security-smoke/.git/config \
    /security-smoke/nested/.hidden /security-smoke/auth.json \
    /security-smoke/private.pem /security-smoke/database.sqlite \
    /security-smoke/evil.php /security-smoke/evil.PHP /security-smoke/evil.phtml; do
    code="$(curl --silent --path-as-is --max-time 5 --output "$temporary_directory/denied" --write-out '%{http_code}' "$base_url$path")"
    test "$code" = 404
    ! grep -Eq 'NGINX_SECRET_CANARY|ARBITRARY_PHP_EXECUTED|<\?php' "$temporary_directory/denied"
done
code="$(curl --silent --max-time 5 --output "$temporary_directory/symlink" --write-out '%{http_code}' "$base_url/security-smoke/passwd-link")"
test "$code" = 404
! grep -q 'root:x:' "$temporary_directory/symlink"
test "$(curl --silent --max-time 5 --output /dev/null --write-out '%{http_code}' "$base_url/build/missing.js")" = 404

stage=asset-release-identity
docker exec "$php_container" sh -ec 'find public/build -type f -exec sha256sum {} +' >"$temporary_directory/assets"
while read -r expected_hash path; do
    curl --silent --fail --max-time 10 "$base_url/${path#public/}" >"$temporary_directory/asset"
    test "$(sha256sum "$temporary_directory/asset" | cut -d' ' -f1)" = "$expected_hash"
done <"$temporary_directory/assets"

stage=body-limit
head -c 2200000 /dev/zero >"$temporary_directory/oversized-body"
test "$(curl --silent --max-time 10 --header 'Expect:' --data-binary "@$temporary_directory/oversized-body" --output /dev/null --write-out '%{http_code}' "$base_url/login")" = 413

stage=logs-and-shutdown
docker logs "$nginx_container" >"$temporary_directory/access-logs" 2>&1
grep -q 'GET /up HTTP/1.1.*200' "$temporary_directory/access-logs"
grep -q 'client intended to send too large body' "$temporary_directory/access-logs"
docker stop --time 15 "$nginx_container" "$php_container" >/dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' "$nginx_container")" = 0
test "$(docker inspect --format '{{.State.ExitCode}}' "$php_container")" = 0
printf '%s\n' 'Nginx smoke passed: non-root, separate FPM upstream, public-only, blocked secrets/PHP, exact release assets, limits, HTTP health, logs.'
