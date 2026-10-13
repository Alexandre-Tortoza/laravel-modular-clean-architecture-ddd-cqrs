#!/usr/bin/env bash
set -euo pipefail

source_checkout="${1:-}"
if ! source_checkout="$(git -C "$source_checkout" rev-parse --show-toplevel 2>/dev/null)"; then
    printf '%s\n' 'Provide a Git template checkout.' >&2
    exit 2
fi
source_head="$(git -C "$source_checkout" rev-parse HEAD)"
temporary_directory="$(mktemp -d)"
consumer="$temporary_directory/consumer"
stage=tracked-copy
cleanup() {
    result=$?
    if test "$result" -ne 0; then printf 'Independent template smoke failed during %s.\n' "$stage" >&2; fi
    python - "$temporary_directory" <<'PY'
import pathlib, shutil, sys
directory = pathlib.Path(sys.argv[1])
shutil.rmtree(directory)
PY
    exit "$result"
}
trap cleanup EXIT
git -C "$source_checkout" status --porcelain=v1 -z >"$temporary_directory/source-status"
git -C "$source_checkout" ls-files --cached -z >"$temporary_directory/tracked-files"
mkdir "$consumer"
tar -C "$source_checkout" --null --verbatim-files-from --files-from="$temporary_directory/tracked-files" -cf "$temporary_directory/template.tar"
tar -C "$consumer" -xf "$temporary_directory/template.tar"
test ! -e "$consumer/.git"
test ! -e "$consumer/.env"
test ! -e "$consumer/vendor"
test ! -e "$consumer/node_modules"
test -f "$consumer/README.md"
test -f "$consumer/LICENSE"

stage=independent-history-and-metadata
git -C "$consumer" init --quiet --initial-branch=main
git -C "$consumer" config user.name 'Template Smoke'
git -C "$consumer" config user.email 'smoke@example.invalid'
git -C "$consumer" config core.hooksPath /dev/null
git -C "$consumer" add --all
git -C "$consumer" -c commit.gpgsign=false commit --quiet -m 'Initialize independent template consumer'
test "$(git -C "$consumer" rev-list --all --count)" = 1
test -z "$(git -C "$consumer" remote)"
test "$(git -C "$consumer" rev-parse HEAD)" != "$source_head"
python - "$consumer" <<'PY'
import json, pathlib, re, subprocess, sys
root = pathlib.Path(sys.argv[1])
composer = json.loads((root / 'composer.json').read_text())
package = json.loads((root / 'package.json').read_text())
assert composer['type'] == 'project' and composer['license'] == 'MIT'
assert 'template' in composer['keywords'] and package['private'] is True
assert re.fullmatch(r'[a-z0-9-]+/[a-z0-9-]+', composer['name'])
paths = subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z']).decode().split('\0')
for name in filter(None, paths):
    assert name not in ['.env', 'auth.json', 'public/hot']
    assert not name.startswith(('vendor/', 'node_modules/', 'public/build/', 'resources/js/actions/', 'resources/js/routes/', 'resources/js/wayfinder/', 'storage/coverage/'))
    content = (root / name).read_bytes()
    assert not re.search(rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', content), name
    if name.startswith('.env'):
        assert not re.search(rb'(?m)^APP_KEY=base64:[A-Za-z0-9+/=]+', content), name
PY

stage=clean-install
unset APP_NAME APP_KEY APP_ENV DB_URL DB_DATABASE DB_CONNECTION CACHE_STORE SESSION_DRIVER QUEUE_CONNECTION
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_PROFILE AWS_ROLE_ARN AWS_WEB_IDENTITY_TOKEN_FILE
unset COMPOSER_VENDOR_DIR RUN_REDIS_TESTS
export COMPOSER_HOME="$temporary_directory/composer-home" COMPOSER_NO_INTERACTION=1
cd "$consumer"
lockfiles_before="$(sha256sum composer.lock package-lock.json)"
composer setup --no-interaction
test "$lockfiles_before" = "$(sha256sum composer.lock package-lock.json)"
test -f database/database.sqlite
test -s public/build/manifest.json
php artisan db:seed --no-interaction
php -r '$database = new PDO("sqlite:database/database.sqlite"); foreach (["users", "teams", "team_members"] as $table) { if ((int) $database->query("SELECT count(*) FROM ".$table)->fetchColumn() !== 0) { throw new RuntimeException("Default setup or seeding created demo records."); } }'

stage=consumer-name-and-stable-key
sed -i 's/^APP_NAME=.*/APP_NAME="Independent Template Smoke"/' .env
environment_before="$(sha256sum .env)"
composer setup --no-interaction
test "$environment_before" = "$(sha256sum .env)"
test "$lockfiles_before" = "$(sha256sum composer.lock package-lock.json)"
php artisan about --only=environment --json --no-interaction >"$temporary_directory/about.json"
python - "$temporary_directory/about.json" .env <<'PY'
import json, pathlib, sys
assert json.loads(pathlib.Path(sys.argv[1]).read_text())['environment']['application_name'] == 'Independent Template Smoke'
environment = pathlib.Path(sys.argv[2]).read_text()
assert '\nAPP_KEY=base64:' in environment
assert '\nDB_CONNECTION=sqlite\n' in environment
for key in ['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION']:
    assert f'\n{key}=database\n' in environment
PY

stage=consumer-authentication-teams-and-quality
composer ci:check
test "$environment_before" = "$(sha256sum .env)"
test "$lockfiles_before" = "$(sha256sum composer.lock package-lock.json)"
test -z "$(git status --porcelain=v1)"
git -C "$source_checkout" status --porcelain=v1 -z >"$temporary_directory/source-status-after"
cmp "$temporary_directory/source-status" "$temporary_directory/source-status-after"
test "$(git -C "$source_checkout" rev-parse HEAD)" = "$source_head"
printf '%s\n' 'Independent template passed: one fresh local commit, no remote, clean locked install, metadata/secret/artifact checks, custom APP_NAME, stable key, zero default demo records, auth/Teams tests and frontend build without AWS or Redis.'
