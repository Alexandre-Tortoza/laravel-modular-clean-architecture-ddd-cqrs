#!/usr/bin/env bash
set -euo pipefail
container="rds-role-smoke-${RANDOM}-${RANDOM}"
started=false
cleanup() {
    result=$?
    if test "$started" = true; then docker rm --force "$container" >/dev/null 2>&1; fi
    exit "$result"
}
trap cleanup EXIT
image='postgres:16.15-bookworm@sha256:0ea6700a3b4f0ae6ce746519073558aed4d88a79d8d07622a9a644946c7319c4'
password="$(openssl rand -hex 24)"
docker run --detach --name "$container" --network none --user postgres \
    --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=0700 \
    --tmpfs /var/run/postgresql:uid=999,gid=999,mode=0750 \
    --env "POSTGRES_PASSWORD=$password" --env POSTGRES_DB=smoke_role "$image" >/dev/null
started=true
for attempt in $(seq 1 60); do
    if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres -d smoke_role >/dev/null 2>&1; then break; fi
    sleep 1
done
psql=(docker exec --interactive "$container" psql -X --set ON_ERROR_STOP=1 -U postgres -d smoke_role)
"${psql[@]}" --set database_name=smoke_role --set application_username=smoke_application < infra/aws/templates/rds-application-role.sql >/dev/null
flags="$("${psql[@]}" --tuples-only --no-align --command "SELECT rolcanlogin AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls FROM pg_roles WHERE rolname = 'smoke_application'")"
test "$flags" = t
"${psql[@]}" --command "SET ROLE smoke_application; CREATE TABLE role_probe (id integer PRIMARY KEY); INSERT INTO role_probe VALUES (1); SELECT * FROM role_probe;" >/dev/null
for forbidden in 'CREATE ROLE forbidden_role' 'ALTER ROLE smoke_application SUPERUSER' 'CREATE DATABASE forbidden_database'; do
    if "${psql[@]}" --command "SET ROLE smoke_application; $forbidden" >/dev/null 2>&1; then
        printf '%s\n' 'Application role unexpectedly accepted an administrative operation.' >&2
        exit 1
    fi
done
if "${psql[@]}" --set database_name=smoke_role --set application_username=smoke_application < infra/aws/templates/rds-application-role.sql >/dev/null 2>&1; then
    printf '%s\n' 'Existing role unexpectedly overwritten.' >&2
    exit 1
fi
if "${psql[@]}" --set database_name=unexpected --set application_username=wrong_database_role < infra/aws/templates/rds-application-role.sql >/dev/null 2>&1; then
    printf '%s\n' 'Unexpected target database accepted.' >&2
    exit 1
fi
test "$("${psql[@]}" --tuples-only --no-align --command "SELECT count(*) FROM pg_roles WHERE rolname IN ('forbidden_role', 'wrong_database_role')")" = 0
docker rm --force "$container" >/dev/null
started=false
printf '%s\n' 'PostgreSQL 16 role contract passed: application DDL/DML, no administrator privileges, no overwritten role and no wrong-database writes. No RDS was accessed.'
