#!/usr/bin/env bash
set -euo pipefail
php_image="${1:?Provide the PHP runtime image}"
nginx_image="${2:?Provide the nginx-tls image}"
revision="${3:?Provide the checked-out source SHA}"
directory="$(mktemp -d)"
project="https-smoke-${RANDOM}-${RANDOM}"
domain=tls.smoke.invalid
stage=environment
started=false
compose=(docker compose --env-file "$directory/compose.env" -p "$project" -f compose.production.https.yaml -f "$directory/fixture.yaml")
cleanup() {
    result=$?
    if test "$result" -ne 0; then
        printf 'HTTPS smoke failed during %s.\n' "$stage" >&2
        if test "$started" = true; then "${compose[@]}" logs >&2 || true; fi
    fi
    if test "$started" = true; then "${compose[@]}" down --volumes --timeout 30 >/dev/null 2>&1 || true; fi
    # Only ephemeral fixtures owned by this test; sudo never accesses a real host certificate.
    sudo rm -rf "$directory"
    exit "$result"
}
trap cleanup EXIT
umask 0077
chmod 0755 "$directory"
mkdir -m 0755 "$directory/acme" "$directory/uploads" "$directory/security"
mkdir -p "$directory/acme/.well-known/acme-challenge"
printf 'ephemeral-acme-token\n' >"$directory/acme/.well-known/acme-challenge/smoke-token"
printf 'TLS_PRIVATE_CANARY\n' >"$directory/security/private.pem"
chmod -R a+rX "$directory/acme" "$directory/security"
ln -s /run/tls/current/privkey.pem "$directory/security/key-link"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=Ephemeral HTTPS smoke CA' \
    -addext 'basicConstraints=critical,CA:true' -keyout "$directory/ca-key.pem" -out "$directory/ca.pem" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s\nextendedKeyUsage=serverAuth\nbasicConstraints=critical,CA:false\n' "$domain" >"$directory/leaf-extensions"
issue_certificate() {
    openssl req -new -newkey rsa:2048 -nodes -subj "/CN=$domain" \
        -keyout "$directory/key.pem" -out "$directory/request.csr" >/dev/null 2>&1
    openssl x509 -req -in "$directory/request.csr" -CA "$directory/ca.pem" -CAkey "$directory/ca-key.pem" \
        -set_serial "0x$(openssl rand -hex 16)" -days 2 -extfile "$directory/leaf-extensions" -out "$directory/cert.pem" >/dev/null 2>&1
    sudo python3 infra/aws/https/install-certificate.py --domain "$domain" --mechanism acme-webroot \
        --fullchain "$directory/cert.pem" --private-key "$directory/key.pem" --directory "$directory/tls" --nginx-gid 101
}
issue_certificate
first_serial="$(openssl x509 -in "$directory/cert.pem" -serial -noout)"
password="$(openssl rand -hex 24)"
export PRODUCTION_PHP_IMAGE="$php_image" PRODUCTION_NGINX_TLS_IMAGE="$nginx_image"
export PRODUCTION_UPLOAD_DIRECTORY="$directory/uploads" PRODUCTION_RUNTIME_ENV_FILE="$directory/runtime.env"
export TLS_DOMAIN="$domain" TLS_CERTIFICATE_MECHANISM=acme-webroot
export TLS_CERTIFICATE_DIRECTORY="$directory/tls" TLS_ACME_WEBROOT="$directory/acme" TLS_BIND_IPV4=127.0.0.1
export SMOKE_DB_DATABASE="smoke_${RANDOM}_${RANDOM}" SMOKE_DB_PASSWORD="$(openssl rand -hex 24)"
touch "$directory/compose.env"
cat >"$directory/runtime.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://$domain
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
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=array
SMOKE_TEST_PASSWORD=$password
ENV
cat >"$directory/fixture.yaml" <<YAML
services:
    php:
        volumes: !override ['app-storage:/app/storage/app']
        networks: [app, database]
        depends_on:
            pgsql: {condition: service_healthy}
    nginx:
        ports: !override ['127.0.0.1::8081', '127.0.0.1::8443']
        volumes:
            - type: bind
              source: '$directory/security'
              target: /app/public/tls-security
              read_only: true
    pgsql:
        image: postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4
        user: postgres
        environment:
            POSTGRES_DB: '\${SMOKE_DB_DATABASE}'
            POSTGRES_USER: smoke
            POSTGRES_PASSWORD: '\${SMOKE_DB_PASSWORD}'
        volumes: ['database:/var/lib/postgresql/data']
        networks: [database]
        healthcheck:
            test: ['CMD-SHELL', 'pg_isready -U smoke -d "\$\$POSTGRES_DB"']
            interval: 2s
            timeout: 5s
            retries: 30
        cap_drop: [ALL]
        security_opt: ['no-new-privileges:true']
volumes:
    app-storage:
    database:
networks:
    database:
        driver: bridge
        internal: true
YAML
stage=invalid-runtime-inputs
for invalid in 'bad.invalid;return 200;' '*.smoke.invalid' 'https://tls.smoke.invalid' ''; do
    if docker run --rm --network none --env "TLS_DOMAIN=$invalid" --env TLS_CERTIFICATE_MECHANISM=provided "$nginx_image" >"$directory/invalid-domain" 2>&1; then exit 1; fi
    grep -q 'TLS requires an explicit valid domain' "$directory/invalid-domain"
done
if docker run --rm --network none --env "TLS_DOMAIN=$domain" --env TLS_CERTIFICATE_MECHANISM=automatic "$nginx_image" >"$directory/invalid-mechanism" 2>&1; then exit 1; fi
grep -q 'TLS requires an explicit valid domain' "$directory/invalid-mechanism"
stage=compose-start
"${compose[@]}" config --quiet
started=true
"${compose[@]}" up --detach --no-build --wait --wait-timeout 90
"${compose[@]}" exec -T -e SMOKE_ACTION=initialize php php <tests/Docker/production-fixture.php >"$directory/fixture.json"
http_port="$("${compose[@]}" port nginx 8081 | cut -d: -f2)"
tls_port="$("${compose[@]}" port nginx 8443 | cut -d: -f2)"
url="https://$domain"
https() { curl --noproxy '*' --silent --show-error --max-time 10 --cacert "$directory/ca.pem" --connect-to "$domain:443:127.0.0.1:$tls_port" "$@"; }
http() { curl --noproxy '*' --silent --max-time 10 --header "Host: $domain" "$@"; }
stage=https-termination
https --fail "$url/up" >"$directory/up"
grep -q 'Application up' "$directory/up"
https --fail "$url/ready" >/dev/null
https --fail --cookie-jar "$directory/cookies" --dump-header "$directory/headers" "$url/login" >"$directory/login"
grep -qi '^strict-transport-security: max-age=31536000' "$directory/headers"
grep -Ei '^set-cookie: .*secure' "$directory/headers" | grep -qi 'httponly'
# Native request URL comes from direct TLS/FastCGI, even with attacker-supplied forwarded assertions.
https --header 'X-Forwarded-Proto: http' --header 'X-Forwarded-Host: attacker.invalid' \
    --dump-header "$directory/redirect" --output /dev/null "$url/settings/teams"
grep -qi "^location: https://$domain/login" "$directory/redirect"
"${compose[@]}" exec -T php php artisan config:show trustedproxy >"$directory/proxies"
! grep -q 'REMOTE_ADDR\|\*\|0.0.0.0' "$directory/proxies"
https --fail "$url/build/release.json" >"$directory/release.json"
python3 - "$directory/release.json" "$revision" <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text())['source_revision'] == sys.argv[2]
PY
stage=session-login
csrf="$(python3 - "$directory/cookies" <<'PY'
import pathlib, sys, urllib.parse
for line in pathlib.Path(sys.argv[1]).read_text().splitlines():
    fields = line.split('\t')
    if len(fields) == 7 and fields[5] == 'XSRF-TOKEN':
        print(urllib.parse.unquote(fields[6]))
        break
else:
    raise SystemExit('Missing CSRF cookie')
PY
)"
code="$(https --cookie "$directory/cookies" --cookie-jar "$directory/cookies" --header 'Accept: application/json' \
    --header "X-XSRF-TOKEN: $csrf" --data-urlencode 'email=owner@smoke.example.invalid' --data-urlencode "password=$password" \
    --output "$directory/auth.json" --write-out '%{http_code}' "$url/login")"
test "$code" = 200
https --fail --cookie "$directory/cookies" "$url/settings/teams" >"$directory/teams"
grep -q 'Smoke Owner Personal' "$directory/teams"
stage=http-and-private-boundaries
for path in /login /up /ready /build/release.json /.env; do
    code="$(http --dump-header "$directory/http-headers" --output "$directory/http-body" --write-out '%{http_code}' "http://127.0.0.1:$http_port$path")"
    test "$code" = 308
    grep -qi "^location: https://$domain$path" "$directory/http-headers"
    ! grep -qi '^set-cookie:' "$directory/http-headers"
    ! grep -q 'Application up\|TLS_PRIVATE_CANARY' "$directory/http-body"
done
test "$(http --data 'email=owner@smoke.example.invalid' --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$http_port/login")" = 308
http --fail "http://127.0.0.1:$http_port/.well-known/acme-challenge/smoke-token" >"$directory/challenge"
grep -q '^ephemeral-acme-token$' "$directory/challenge"
for path in /.env /.git/config /composer.json /config/app.php /run/tls/current/privkey.pem /tls-security/private.pem /tls-security/key-link; do
    test "$(https --output "$directory/denied" --write-out '%{http_code}' "$url$path")" = 404
    ! grep -q 'TLS_PRIVATE_CANARY\|PRIVATE KEY' "$directory/denied"
done
test "$(https --header 'Host: attacker.invalid' --output /dev/null --write-out '%{http_code}' "$url/")" = 421
if curl --noproxy '*' --silent --max-time 5 --cacert "$directory/ca.pem" \
    --connect-to "attacker.invalid:443:127.0.0.1:$tls_port" https://attacker.invalid/up >/dev/null 2>&1; then exit 1; fi
container="$("${compose[@]}" ps -q nginx)"
docker inspect "$container" | python3 -c 'import json,sys; c=json.load(sys.stdin)[0]; assert c["Config"]["User"]=="nginx:nginx"; assert all(not m["RW"] for m in c["Mounts"] if m["Destination"] in ["/run/tls","/run/acme"]); assert not c["NetworkSettings"]["Ports"].get("8080/tcp")'
stage=offline-renewal-and-reload
issue_certificate
test "$first_serial" != "$(openssl x509 -in "$directory/cert.pem" -serial -noout)"
"${compose[@]}" exec -T nginx nginx -t -c /tmp/nginx/tls.conf
"${compose[@]}" exec -T nginx nginx -s reload -c /tmp/nginx/tls.conf
for attempt in {1..40}; do
    if https --fail "$url/up" >/dev/null 2>&1; then break; fi
    sleep 0.25
done
https --fail --cookie "$directory/cookies" "$url/settings/teams" >/dev/null
for attempt in {1..40}; do
    served_serial="$(openssl s_client -connect "127.0.0.1:$tls_port" -servername "$domain" </dev/null 2>/dev/null | openssl x509 -serial -noout)"
    if test "$served_serial" = "$(openssl x509 -in "$directory/cert.pem" -serial -noout)"; then break; fi
    sleep 0.25
done
test "$served_serial" = "$(openssl x509 -in "$directory/cert.pem" -serial -noout)"
stage=non-webroot-mode
TLS_CERTIFICATE_MECHANISM=provided "${compose[@]}" up --detach --no-build --force-recreate nginx
http_port="$("${compose[@]}" port nginx 8081 | cut -d: -f2)"
tls_port="$("${compose[@]}" port nginx 8443 | cut -d: -f2)"
url="https://$domain"
for attempt in {1..40}; do
    if https --fail "$url/up" >/dev/null 2>&1; then break; fi
    sleep 0.25
done
# Recreating changes ephemeral host ports: resolve them again.
http_port="$("${compose[@]}" port nginx 8081 | cut -d: -f2)"
tls_port="$("${compose[@]}" port nginx 8443 | cut -d: -f2)"
url="https://$domain"
test "$(http --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$http_port/.well-known/acme-challenge/smoke-token")" = 404
https --fail "$url/up" >/dev/null
stage=owned-resource-cleanup
"${compose[@]}" down --volumes --timeout 30
started=false
test -z "$(docker ps --all --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker volume ls --quiet --filter "label=com.docker.compose.project=$project")"
test -z "$(docker network ls --quiet --filter "label=com.docker.compose.project=$project")"
printf '%s\n' 'HTTPS smoke passed: direct TLS/FastCGI, canonical HTTP redirect, safe webroot, secure sessions, rejected private files/hosts, readonly keys and offline renewal/reload.'
