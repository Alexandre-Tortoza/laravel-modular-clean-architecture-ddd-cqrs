#!/usr/bin/env bash
set -euo pipefail
if test "$#" -ne 0 || test "${GITHUB_ACTIONS:-}" != true || test "${RUNNER_ENVIRONMENT:-}" != github-hosted \
    || test "${RUNNER_OS:-}" != Linux || test "${BACKUP_RESTORE_TEST_ACK:-}" != disposable-only \
    || test -n "${DOCKER_HOST:-}" || test -n "${DOCKER_CONTEXT:-}"; then
    printf '%s\n' 'Backup rehearsal accepts no targets and requires an explicitly acknowledged disposable GitHub-hosted Linux runner.' >&2
    exit 2
fi
test "$(docker context show)" = default
test "$(docker context inspect default --format '{{.Endpoints.docker.Host}}')" = unix:///var/run/docker.sock
umask 0077
temporary="$(mktemp -d "$RUNNER_TEMP/backup-restore.XXXXXXXX")"
token="$(openssl rand -hex 16)"
prefix="backup-restore-$token"
image='postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4'
source_container="$prefix-source"
target_container="$prefix-target"
source_volume="$prefix-uploads-source"
target_volume="$prefix-uploads-target"
stage=owned-resource-creation
started_at="$(date -u +%FT%TZ)"
cleanup() {
    result=$?
    cleanup_failed=false
    if test "$result" -ne 0; then printf 'Disposable backup rehearsal failed during %s; fixture diagnostics withheld.\n' "$stage" >&2; fi
    if containers="$(docker ps --all --filter "label=template.backup-fixture=$token" --format '{{.ID}}' 2>/dev/null)"; then
        for container in $containers; do
            if ! docker rm --force "$container" >/dev/null 2>&1; then cleanup_failed=true; fi
        done
    else
        cleanup_failed=true
    fi
    if volumes="$(docker volume ls --filter "label=template.backup-fixture=$token" --format '{{.Name}}' 2>/dev/null)"; then
        for volume in $volumes; do
            if ! docker volume rm "$volume" >/dev/null 2>&1; then cleanup_failed=true; fi
        done
    else
        cleanup_failed=true
    fi
    if remaining_containers="$(docker ps --all --filter "label=template.backup-fixture=$token" --format '{{.ID}}' 2>/dev/null)" \
        && remaining_volumes="$(docker volume ls --filter "label=template.backup-fixture=$token" --format '{{.Name}}' 2>/dev/null)"; then
        if test -n "$remaining_containers" || test -n "$remaining_volumes"; then cleanup_failed=true; fi
    else
        cleanup_failed=true
    fi
    if ! rm -rf "$temporary"; then cleanup_failed=true; fi
    if test "$cleanup_failed" = true; then
        printf '%s\n' 'Owned backup fixture cleanup failed; diagnostics withheld. No shared-resource cleanup attempted.' >&2
        if test "$result" -eq 0; then result=1; fi
    fi
    exit "$result"
}
trap cleanup EXIT
for volume in "$source_volume" "$target_volume"; do
    if docker volume inspect "$volume" >/dev/null 2>&1; then
        printf '%s\n' 'The unique fixture volume unexpectedly exists; refusing reuse.' >&2
        exit 1
    fi
    docker volume create --label "template.backup-fixture=$token" "$volume" >/dev/null
    test "$(docker volume inspect --format '{{index .Labels "template.backup-fixture"}}' "$volume")" = "$token"
    docker run --rm --label "template.backup-fixture=$token" --network none --read-only --user 0:0 --cap-drop ALL --cap-add CHOWN --security-opt no-new-privileges:true \
        --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
        --mount "type=volume,source=$volume,target=/uploads" --entrypoint sh "$image" \
        -ec 'mkdir -p /uploads/private; chmod 0700 /uploads /uploads/private; chown 33:33 /uploads /uploads/private'
done
start_database() {
    docker run --detach --name "$1" --label "template.backup-fixture=$token" --network none --user postgres \
        --cap-drop ALL --security-opt no-new-privileges:true \
        --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
        --tmpfs /var/run/postgresql:uid=999,gid=999,mode=0750 \
        --env "POSTGRES_PASSWORD=$(openssl rand -hex 24)" --env "POSTGRES_DB=$2" "$image" >/dev/null
    for attempt in {1..60}; do
        if docker exec "$1" pg_isready -h 127.0.0.1 -U postgres -d "$2" >/dev/null 2>&1; then return; fi
        sleep 1
    done
    return 1
}
start_database "$source_container" fixture_source
start_database "$target_container" fixture_recovered
for container in "$source_container" "$target_container"; do
    test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$container")" = none
    test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$container")" = 0
done
sql() { docker exec --interactive "$1" psql -X --set ON_ERROR_STOP=1 --tuples-only --no-align -U postgres -d "$2" "${@:3}"; }
stage=source-fixtures
revision="$(git rev-parse HEAD)"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]]
cat >"$temporary/source.sql" <<'SQL'
CREATE TABLE users (id bigserial PRIMARY KEY, name varchar(255) NOT NULL, email varchar(255) NOT NULL UNIQUE, password varchar(255) NOT NULL);
CREATE TABLE sessions (id varchar(255) PRIMARY KEY, user_id bigint REFERENCES users(id), ip_address varchar(45), user_agent text, payload text NOT NULL, last_activity integer NOT NULL);
CREATE INDEX sessions_user_id_index ON sessions(user_id);
CREATE INDEX sessions_last_activity_index ON sessions(last_activity);
CREATE TABLE jobs (id bigserial PRIMARY KEY, queue varchar(255) NOT NULL, payload text NOT NULL, attempts smallint NOT NULL, reserved_at integer, available_at integer NOT NULL, created_at integer NOT NULL);
CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL);
CREATE TABLE _template_release_migrations (source_revision varchar(40) PRIMARY KEY, artifact_fingerprint varchar(64) NOT NULL, completed_at timestamptz NOT NULL);
INSERT INTO users(name,email,password) VALUES ('fixture-one','one@fixture.invalid','synthetic-password-hash'), ('fixture-two','two@fixture.invalid','synthetic-password-hash');
INSERT INTO sessions VALUES ('synthetic-session',1,'192.0.2.1','backup-fixture','synthetic-private-session-payload',1900000000);
INSERT INTO jobs(queue,payload,attempts,available_at,created_at) VALUES ('default','synthetic-private-job-payload',0,1900000000,1900000000);
INSERT INTO migrations(migration,batch) VALUES ('representative_restore_fixture',1);
INSERT INTO _template_release_migrations VALUES (:'revision',repeat('b',64),'2026-10-12T12:00:00Z');
SQL
sql "$source_container" fixture_source --set "revision=$revision" <"$temporary/source.sql" >/dev/null 2>"$temporary/seed.log"
cat >"$temporary/fingerprint.sql" <<'SQL'
SELECT jsonb_build_object(
  'users', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM users t),
  'sessions', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM sessions t),
  'jobs', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM jobs t),
  'migrations', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM migrations t),
  'receipts', (SELECT jsonb_agg(to_jsonb(t) ORDER BY source_revision) FROM _template_release_migrations t),
  'columns', (SELECT jsonb_agg(jsonb_build_array(table_name,column_name,data_type,is_nullable,column_default) ORDER BY table_name,ordinal_position) FROM information_schema.columns WHERE table_schema='public'),
  'constraints', (SELECT jsonb_agg(jsonb_build_array(c.relname,t.conname,pg_get_constraintdef(t.oid)) ORDER BY c.relname,t.conname) FROM pg_constraint t JOIN pg_class c ON c.oid=t.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'),
  'indexes', (SELECT jsonb_agg(jsonb_build_array(tablename,indexname,indexdef) ORDER BY tablename,indexname) FROM pg_indexes WHERE schemaname='public'),
  'users_sequence', (SELECT jsonb_build_array(last_value,is_called) FROM users_id_seq),
  'jobs_sequence', (SELECT jsonb_build_array(last_value,is_called) FROM jobs_id_seq)
)::text;
SQL
fingerprint() { sql "$1" "$2" <"$temporary/fingerprint.sql" 2>"$temporary/fingerprint.log"; }
fingerprint "$source_container" fixture_source >"$temporary/source-before.json"
source_hash="$(sha256sum "$temporary/source-before.json" | cut -d ' ' -f1)"
printf 'base64:%s\n' "$(openssl rand -base64 32)" >"$temporary/source-app-key"
printf 'Synthetic private upload preserved by logical rehearsal.\n' >"$temporary/document.txt"
openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$temporary/source-app-key" -in "$temporary/document.txt" -out "$temporary/document.enc"
docker run --rm --label "template.backup-fixture=$token" --interactive --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
    --mount "type=volume,source=$source_volume,target=/uploads" --entrypoint sh "$image" \
    -ec 'umask 0077; cat > /uploads/private/document.enc' <"$temporary/document.enc"
upload_signature() {
    docker run --rm --label "template.backup-fixture=$token" --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges:true \
        --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
        --mount "type=volume,source=$1,target=/uploads,readonly" --entrypoint sh "$image" \
        -ec 'cd /uploads; sha256sum private/document.enc; stat --format="%a:%u:%g" . private private/document.enc'
}
upload_signature "$source_volume" >"$temporary/uploads-before"
upload_hash="$(sha256sum "$temporary/uploads-before" | cut -d ' ' -f1)"

stage=read-only-backup
docker exec "$source_container" pg_dump -U postgres -d fixture_source --format=custom --no-owner --no-acl \
    >"$temporary/database.dump" 2>"$temporary/dump.log"
backup_hash="$(sha256sum "$temporary/database.dump" | cut -d ' ' -f1)"
docker run --rm --label "template.backup-fixture=$token" --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
    --mount "type=volume,source=$source_volume,target=/uploads,readonly" --entrypoint tar "$image" \
    -C /uploads -cf - . >"$temporary/uploads.tar"
restore_database() {
    test "$(sql "$target_container" fixture_recovered --command "SELECT count(*) FROM pg_tables WHERE schemaname='public'" 2>"$temporary/empty-check.log")" = 0 || return 2
    docker exec --interactive "$target_container" pg_restore -U postgres -d fixture_recovered --no-owner --no-acl --exit-on-error --single-transaction \
        <"$1" >/dev/null 2>"$temporary/restore.log"
}
stage=corrupt-backup-refusal
head -c 32 "$temporary/database.dump" >"$temporary/corrupt.dump"
if restore_database "$temporary/corrupt.dump"; then printf '%s\n' 'Corrupt dump unexpectedly restored.' >&2; exit 1; fi
test "$(sql "$target_container" fixture_recovered --command "SELECT count(*) FROM pg_tables WHERE schemaname='public'")" = 0
stage=new-database-restore
restore_database "$temporary/database.dump"
fingerprint "$target_container" fixture_recovered >"$temporary/restored.json"
cmp -s "$temporary/source-before.json" "$temporary/restored.json"
if restore_database "$temporary/database.dump"; then printf '%s\n' 'Existing target unexpectedly accepted overwrite.' >&2; exit 1; fi
fingerprint "$target_container" fixture_recovered >"$temporary/after-refusal.json"
cmp -s "$temporary/restored.json" "$temporary/after-refusal.json"
test "$(sql "$target_container" fixture_recovered --command "INSERT INTO users(name,email,password) VALUES ('new','three@fixture.invalid','synthetic') RETURNING id" | head -1)" = 3
if sql "$target_container" fixture_recovered --command "INSERT INTO users(name,email,password) VALUES ('duplicate','one@fixture.invalid','synthetic')" >/dev/null 2>"$temporary/constraint.log"; then
    printf '%s\n' 'Restored unique constraint was lost.' >&2; exit 1
fi

stage=new-upload-volume-restore
docker run --rm --label "template.backup-fixture=$token" --interactive --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
    --mount "type=volume,source=$target_volume,target=/uploads" --entrypoint tar "$image" \
    --no-same-owner --same-permissions -C /uploads -xf - <"$temporary/uploads.tar"
upload_signature "$target_volume" >"$temporary/uploads-restored"
cmp -s "$temporary/uploads-before" "$temporary/uploads-restored"
docker run --rm --label "template.backup-fixture=$token" --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
    --mount "type=volume,source=$target_volume,target=/uploads,readonly" --entrypoint cat "$image" \
    /uploads/private/document.enc >"$temporary/restored-document.enc"
# APP_KEY is recovered independently, never from the SQL/upload archive or admin credentials.
cp "$temporary/source-app-key" "$temporary/recovered-app-key"
openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$temporary/recovered-app-key" -in "$temporary/restored-document.enc" -out "$temporary/restored-document.txt" 2>"$temporary/decrypt.log"
cmp -s "$temporary/document.txt" "$temporary/restored-document.txt"
openssl rand -base64 32 >"$temporary/wrong-app-key"
if openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$temporary/wrong-app-key" -in "$temporary/restored-document.enc" -out "$temporary/wrong-document.txt" 2>"$temporary/wrong-key.log"; then
    ! cmp -s "$temporary/document.txt" "$temporary/wrong-document.txt"
fi
stage=source-integrity-and-sanitized-evidence
fingerprint "$source_container" fixture_source >"$temporary/source-after.json"
upload_signature "$source_volume" >"$temporary/uploads-after"
cmp -s "$temporary/source-before.json" "$temporary/source-after.json"
cmp -s "$temporary/uploads-before" "$temporary/uploads-after"
test "$(sha256sum "$temporary/database.dump" | cut -d ' ' -f1)" = "$backup_hash"
SOURCE_SHA="$revision" SOURCE_DATA_HASH="$source_hash" UPLOAD_HASH="$upload_hash" BACKUP_HASH="$backup_hash" STARTED_AT="$started_at" \
    python3 - <<'PY'
import datetime, json, os
from pathlib import Path
report = dict(schema_version=1, mode='github-hosted-disposable-logical-rehearsal', source_revision=os.environ['SOURCE_SHA'],
              started_at=os.environ['STARTED_AT'], completed_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
              source_logical_sha256=os.environ['SOURCE_DATA_HASH'], upload_inventory_sha256=os.environ['UPLOAD_HASH'], backup_sha256=os.environ['BACKUP_HASH'],
              passed=['new-private-database', 'rows-session-job-receipt-schema-index-sequence-parity', 'corrupt-backup-refusal', 'existing-target-refusal',
                      'new-UID33-upload-volume', 'retained-key-decryption', 'wrong-key-refusal', 'source-unchanged'],
              unproven=['RDS-snapshot-PITR', 'EBS-snapshot', 'Laravel-HTTP-login-cookie', 'measured-production-RPO-RTO'])
Path('backup-restore-evidence.json').write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')
print('Disposable PostgreSQL logical restore and private-upload/key rehearsal passed; source retained. AWS snapshot/PITR/RPO/RTO remain unproven.')
PY
