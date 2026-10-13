# Production images and disposable runtime verification

Use this guide to build the production images and exercise them on a **new disposable Linux Docker host**. Run commands from the repository root in Bash. The smokes create temporary credentials, users, databases and volumes, then remove their own resources. They are not commands to run against an existing application/database. Building and testing does not publish images, provision AWS, install SSH/SSM access or authorize a deployment.

## Prerequisites and image layout

You need Git, Docker with an already accessible daemon, Compose supporting `!override` and [raw env files](https://docs.docker.com/reference/compose-file/services/#format) (2.30 or later), Buildx, Bash, OpenSSL, curl, `sha256sum`, and Python available as both `python` and `python3`. The hosted OCI/release-cycle jobs also install Skopeo. Image builds download the pinned bases and locked dependencies; host Composer/npm/vendor are not required. Verify the selected Docker context belongs to the disposable machine before starting:

```bash
docker context show
docker version
docker compose version
docker buildx version
python --version
python3 --version
```

If daemon access is denied, stop and use the existing disposable GitHub Actions checks, or an independently provisioned host with authorized Docker access. The repository does not require changing socket permissions or escalating Docker commands.

[Dockerfile](../Dockerfile) pins base images by version and digest. Its current PHP runtime is PHP 8.5 FPM; the source lockfile selects Laravel 13. Production is independent of Sail.

| Stage or target           | Responsibility                                                                                                                           |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `php-base`                | PHP-FPM, PostgreSQL PDO, `pcntl`, Redis **client** extension and production PHP settings. No Redis server requirement.                   |
| `composer-build`          | Locked Composer install and package discovery for build tooling.                                                                         |
| `assets`                  | Locked `npm ci`, Wayfinder/Vite build and [release provenance](../docker/frontend-artifact.mjs). Requires full `SOURCE_REVISION`.        |
| `production-dependencies` | Locked `--no-dev` dependencies and authoritative autoload; private build caches removed.                                                 |
| `runtime`                 | Application, production vendor and built `public/`; runs as `www-data:www-data`. No Node/npm, Vite server, Xdebug or development vendor. |
| `nginx`                   | HTTP **fixture** image containing only `public/` and Nginx configuration; runs as `nginx:nginx`.                                         |
| `nginx-tls`               | Direct HTTPS production target, explicit domain/certificate mechanism and read-only certificate mounts.                                  |

Nginx resolves the service name `php` on their shared `app` network and forwards only `/index.php` to FPM on port 9000. Do not publish FPM. Dotfiles, arbitrary PHP/scripts, package/config/SQL/key files and directory listing are rejected. Nginx has no application/vendor/config source tree. PostgreSQL and optional Redis in the disposable Compose have no host ports and use the internal `database` network.

## First result: real disposable PostgreSQL smoke

These are the literal build/test commands used by [production-smoke.yml](../.github/workflows/production-smoke.yml). They build HTTP fixture images from the **same checkout and full SHA**, then verify PHP/Nginx asset byte parity. Local tags below are temporary test transport, not immutable production release identifiers.

```bash
set -euo pipefail
revision="$(git rev-parse HEAD)"
docker build --build-arg "SOURCE_REVISION=$revision" --target runtime --tag laravel-php:production-smoke .
docker build --build-arg "SOURCE_REVISION=$revision" --target nginx --tag laravel-nginx:production-smoke .
bash tests/Docker/production-smoke.sh laravel-php:production-smoke laravel-nginx:production-smoke "$revision" database
```

Expected final output starts with `Production database smoke passed`. The script starts pinned PostgreSQL 16 in [compose.production.smoke.yaml](../compose.production.smoke.yaml), uses a new database/project/named volumes and one temporary APP_KEY, runs test-only migrations/fixtures, and exercises login, CSRF, secure cookie headers, Inertia asset versions, Teams isolation/writes, persisted sessions, every recorded asset hash, missing-key rejection, unavailable FPM detection and graceful resource cleanup. No demo seeder is part of production startup.

This HTTP listener is bound only to an ephemeral localhost port for the fixture. Its configured HTTPS APP_URL and Secure cookie headers are assertions about configuration; they do not make that HTTP connection TLS. Use the separate HTTPS verification below for actual encrypted transport. The script removes its own database/uploads at the end, so this is a functional test, not a persistent interactive installation.

Redis is independently optional. Reuse the same images and select the opt-in test profile:

```bash
bash tests/Docker/production-smoke.sh laravel-php:production-smoke laravel-nginx:production-smoke "$revision" redis
```

Expected: `Production redis smoke passed`. This executes Redis-backed session/cache/queue roundtrips; it is not a second database-driver test renamed Redis. The default invocation starts no Redis service. Actual managed Valkey/TLS/auth configuration is separately described in [runtime secret configuration](../infra/aws/valkey-runtime.config.json.example) and verified by its own [contract workflow](../.github/workflows/managed-valkey-contract.yml).

## Controlled migrations and background processes

The normal image entrypoint validates configuration and creates caches; it **does not migrate, reset schema or seed data**. A release migration is a separate, once-per-release operation using [release-migrate.php](../docker/php/release-migrate.php), [compose.production.migrations.yaml](../compose.production.migrations.yaml) and the `release` profile. It requires PostgreSQL, the full approved source revision matching `/app/public/build/release.json`, and the selected PHP image.

Run the native disposable concurrency/failure test with the already built PHP image:

```bash
bash tests/Docker/migrations-smoke.sh laravel-php:production-smoke "$revision"
```

Expected: `Controlled release migrations passed`. This starts its own private PostgreSQL, tests a failing migration, lock timeout, two concurrent attempts, one successful receipt, safe repeat, artifact mutation refusal and additive N/N-1 compatibility. Its test-only derived images/migrations never become production candidates.

The runner holds a dedicated PostgreSQL advisory-lock session across the native `migrate --force --no-interaction` command. `_template_release_migrations` records source SHA, artifact fingerprint and completion time after success. A completed SHA with different frontend release-manifest bytes is refused; this migration fingerprint is not a whole-image OCI digest. Image integrity is verified separately before promotion. `RELEASE_MIGRATION_LOCK_TIMEOUT` is an integer 0..3600 seconds, default 60; timeout/failure returns nonzero and must block promotion. A failure may have applied earlier migrations; inspect and repair through a reviewed forward plan, not blind schema rollback. Never invoke migrations on every web/worker replica.

[compose.production.processes.yaml](../compose.production.processes.yaml) runs the same PHP image/env for a dedicated database queue worker and one scheduler. Verify the real child-process readiness boundary and the independent process fixture:

```bash
python3 tests/Docker/test_process_readiness.py --image laravel-php:production-smoke -v
bash tests/Docker/processes-smoke.sh laravel-php:production-smoke
```

Expected: the readiness tests pass and the final line starts `Process smoke passed`. This particular fixture uses a **disposable SQLite database**, not PostgreSQL: it proves transactional `afterCommit` delivery, rollback refusal, a real worker, scheduler singleton, process recreation and graceful exit without Redis. The combined PostgreSQL/HTTPS release-cycle check below proves the integrated path. Readiness checks distinguish real PHP Artisan children from init/entrypoint wrappers that merely contain those arguments.

The worker uses `queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600`; the database queue default `retry_after` is 90 seconds. Keep job timeout shorter than retry_after and external-call timeouts bounded. Worker/scheduler have 90-second stop grace; FPM allows 10 seconds for process control, so Docker must allow more than 10. Stop/recreate the processes when promoting immutable images; long-running workers do not load new application code merely because a tag or file changed. Keep scheduler replicas exactly 1, independent of web scaling.

## Production release build: one graph, same assets

For retained production artifacts, use [docker-bake.release.hcl](../docker-bake.release.hcl): one Bake invocation builds `runtime` and **`nginx-tls`** together through the shared assets stage. Use a tested full Git SHA and stable `vMAJOR.MINOR.PATCH`. These local example bytes are not published:

```bash
revision="$(git rev-parse HEAD)"
mkdir -p image-release/local
SOURCE_REVISION="$revision" RELEASE_VERSION=v0.0.0 RELEASE_DIRECTORY=./image-release/local \
  docker buildx bake --file docker-bake.release.hcl release
python3 .github/scripts/image_release.py create --directory image-release/local --source . \
  --source-revision "$revision" --version v0.0.0
python3 .github/scripts/image_release.py verify --directory image-release/local
```

This writes local PHP/Nginx OCI archives plus `image-release.json`. Verification reads real OCI manifest/config/layer descriptors and hashes, non-root users, source/lockfile provenance, actual PHP/Nginx public asset parity and absence of forbidden secrets/build/runtime tools. A Docker image config ID is **not** the OCI manifest digest. Promote verified existing bytes using repository `@sha256:...` references, not `latest`, a mutable local tag or a rebuild. Retain the complete predecessor and its manifest/configuration; do not infer N-1 from a tag.

`public/build/release.json` records source SHA, the four build-input hashes (`composer.json`, `composer.lock`, `package.json`, `package-lock.json`) and the asset inventory. Private values must never become `VITE_*` build inputs: Vite values belong to public client code. Runtime APP_KEY/passwords, `.env`, keys, databases, uploads and previously generated assets are excluded from the build context. Public content deliberately placed in `public/` is packaged in both images. Native `npm ci`/Composer installs and pinned bases do not prove byte-for-byte reproducibility across arbitrary future toolchains or platforms.

The [immutable image workflow](../.github/workflows/immutable-images.yml) verifies a retained predecessor and actual HTTPS recovery without rebuilding. PR jobs require no AWS credentials and upload sanitized manifests only; full OCI archive retention/publication and [publisher](../.github/workflows/publish-images.yml) remain protected opt-ins.

## Runtime configuration and persistent state

Use [.env.production.example](../.env.production.example) as the list of runtime fields; never copy development `.env` or a real runtime file into an image/repository/artifact. Production release Compose reads one private env file with `format: raw`, preserving literal secret bytes. [runtime_secrets.py](../infra/aws/scripts/runtime_secrets.py) prepares the separately approved root-owned 0600 host file from the exact selected application secret; fetching a secret is an external opt-in, not a build step. Its default preview does not fetch secrets or generate a key.

| Runtime contract       | Required behavior                                                                                                                                                                               |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`, `APP_DEBUG` | `production` and `false`; startup refuses another environment/debug mode.                                                                                                                       |
| `APP_KEY`              | Inject a valid, stable key. Missing/invalid keys fail startup. Preserve the same key across replicas, restarts and retained releases; do not call `key:generate` per container/deploy.          |
| `APP_URL`, sessions    | HTTPS URL and `SESSION_SECURE_COOKIE=true`; production example uses HttpOnly and SameSite=lax. TLS must actually terminate at the selected Nginx.                                               |
| `TRUSTED_PROXIES`      | Empty for direct Nginx TLS. If a proxy is introduced, review only its explicit IPs/bounded CIDRs; wildcard/invalid proxies fail startup. Forwarded client headers do not establish trust.       |
| Database               | Real endpoint/app-role credentials; production RDS uses `DB_SSLMODE=verify-full` plus readable `DB_SSLROOTCERT` CA mount. Fixture `disable` applies only to its private disposable PostgreSQL.  |
| Session/cache/queue    | Database defaults; Redis client availability does not select a Redis server. Change each driver explicitly only with its corresponding configured service.                                      |
| Config/route cache     | Each container creates its own private caches after env injection. Recreate containers to consume changed env. Do not share `bootstrap/cache` between services or ship secrets in build caches. |
| Uploads                | Persistent writable mount at `/app/storage/app`, with existing `private`/`public` directories. An absent/read-only/overlay/tmpfs mount is refused before startup.                               |

Laravel13 configuration caching changes where `env()` is read; use application `config()` outside configuration files. See [configuration caching](https://laravel.com/docs/13.x/configuration#configuration-caching). Changing APP_KEY without preserving a reviewed decryption path logs out sessions and can make encrypted data unreadable. Although [Laravel supports previous keys](https://laravel.com/docs/13.x/encryption#gracefully-rotating-encryption-keys), the approved runtime secret delivery currently preserves APP_KEY; this guide does not implement or authorize key rotation.

Uploads are owned by the image's `www-data` UID/GID 33; directories are 750 and private uploaded files are 600. Use correctly provisioned named volumes for disposable tests or the selected persistent EBS mount on an approved host. Do not fix permissions with 777 or automatically format/chown a retained data volume. The local disk points to `storage/app/private`; private uploads are not exposed as static files by Nginx. A disk named `public` does not automatically create a working shared public-upload serving path: Nginx has its own public image tree and rejects symlinks. Design any derived public-file delivery explicitly rather than exposing all storage.

Database, uploads and stable secret/configuration inputs persist across an image replacement. Container source, framework cache/views and bootstrap caches are replaceable. Application/FPM/Nginx output goes to stdout/stderr; the release Compose caps json-file rotation at 10 MiB ×3 files per container. That cap is not an archival, secret-redaction or retention guarantee for arbitrary application logs. Avoid recording credentials, cookies, SQL literals or personal data; [observation](../infra/aws/observation.md) publishes only controlled health events and does not auto-export raw RDS SQL logs.

## Compose contracts and read-only inspection

Use [compose.production.release.yaml](../compose.production.release.yaml) for the complete approved HTTPS release. PHP web, worker, scheduler **and migration** share the same raw env file, upload bind and read-only DB CA bind. Nginx separately mounts its certificate/challenge directories read-only. Bind sources must exist (`create_host_path:false`); a release does not create/format host data paths. The migration service is under the `release` profile and is never included in routine `up` of web/processes.

Do not assemble production web from [compose.production.https.yaml](../compose.production.https.yaml) plus process fragments and assume a web EBS bind replaces their named volume: use the full release file so all four PHP roles have identical storage/configuration. The older HTTPS/process/migration fragments remain fixtures/contracts, not a substitute for the complete host release.

The following canonical check resolves development/production profiles using generated safe placeholders without starting containers or accessing a database/AWS:

```bash
python3 .github/scripts/check_compose.py
```

On a separately approved host, set the reviewed image digests and paths in the shell (the private env file is not a shell script). `config --quiet`, `--services` and `--images` avoid printing expanded secret values. These are **read-only inspection examples**, not a complete deployment:

```bash
# Requires reviewed PHP_IMAGE, NGINX_IMAGE, SOURCE_REVISION, RUNTIME_ENV_FILE,
# UPLOAD_DIRECTORY, DB_CA_BUNDLE, TLS_DOMAIN, TLS_CERTIFICATE_MECHANISM,
# TLS_CERTIFICATE_DIRECTORY, TLS_ACME_WEBROOT and TLS_BIND_IPV4.
docker compose -p "$APPROVED_PROJECT" -f compose.production.release.yaml --profile release config --quiet
docker compose -p "$APPROVED_PROJECT" -f compose.production.release.yaml --profile release config --services
docker compose -p "$APPROVED_PROJECT" -f compose.production.release.yaml --profile release config --images
docker compose -p "$APPROVED_PROJECT" -f compose.production.release.yaml ps
```

For an authorized release, the [controlled deploy helper](../infra/aws/scripts/deploy_release.py) validates image source/version/assets, acquires a host lock, explicitly runs `compose run --rm --no-deps -T migration`, drains processes, promotes web, requires HTTPS readiness, reloads processes and commits a completed receipt only after checks. Use the reviewed [deployment activation contract](../infra/aws/deployment-activation.md); do not bypass it with ad-hoc `up`/`migrate` commands on real data.

## Health, HTTPS and complete release rehearsal

`/up` is application liveness; it may stay 200 while PostgreSQL is down. `/ready` checks the default DB, cache and selected separate session/queue/Redis dependencies: 200 `{"status":"ready"}` or 503 `{"status":"unavailable"}`, both no-store. It does not expose SQL/errors. Treat readiness failure as a blocked promotion, not a reason to switch off dependency checks. The TLS image's internal localhost 8080 `/up` checks Nginx configuration only; production application probes must use the actual HTTPS domain, correct CA and `/ready` through FPM.

The native safeguard smoke verifies this distinction, private encrypted upload persistence, retained APP_KEY/database across recreation, minimal permissions, safe synthetic logs and graceful cleanup:

```bash
bash tests/Docker/runtime-smoke.sh laravel-php:production-smoke laravel-nginx:production-smoke
```

Expected: `Runtime safeguards passed`. Do not turn its fixed observed result into a promise about every derived application's logging or recovery time.

For actual TLS/login/certificate reload, run the existing [https-smoke workflow](../.github/workflows/https-smoke.yml) on its disposable hosted Linux runner. Its canonical fixture invocation is `bash tests/Docker/https-smoke.sh laravel-php:https-smoke laravel-nginx:https-smoke "$(git rev-parse HEAD)"` after building `runtime` and `nginx-tls` at that SHA. The fixture creates a temporary CA/key pair and uses privileged ownership only for its own ephemeral certificate files; do not transplant that privileged invocation to a shared/local host or real certificates. It tests HTTPS/FastCGI, login/secure sessions, HTTP 308 canonical redirects, untrusted Host/forwarded assertions, private-file refusal, ACME challenge policy and offline renewal/Nginx reload. It does not contact an ACME provider, create DNS or obtain a production certificate. [Certificate operation](../infra/aws/https/operation.md) records the external approval boundary.

The [release-cycle workflow](../.github/workflows/release-cycle.yml) exercises the complete production Compose and actual host executors on a disposable hosted runner. It builds two local simulated OCI versions, injects a declared **test-only additive migration** into N+1, then verifies N → N+1 → N: original authenticated cookie/CSRF/Inertia/Teams, each asset byte, DB rows, retained additive schema/data, persistent upload bytes and stable APP_KEY inputs, real queued notification delivery, worker/scheduler replacement and readiness-failure recovery. The source SHA identifies the base checkout; the candidate also includes an explicitly reported fixture overlay; these are not unmodified publishable production releases. Context guards refuse arguments, remote/shared Docker and non-hosted execution. Do not forge those guards for a local machine.

[Rollback](../infra/aws/rollback.md) selects only completed retained receipts/digests and the matching public contract; failed candidates cannot become N-1. It preserves database/uploads/secrets and never reverses migrations or external effects. N/N-1 schema compatibility requires explicit approval. Failures retain phase/identity/timestamps, stop unverified services and propagate nonzero. Interrupted rollback needs an explicit reviewed manual recovery; do not remove volumes or blindly resend a timed-out SSM command. The client records only the acknowledged public command UUID/host before polling.

SSH/SSM access, AWS/ECR credentials, DNS/certificate issuance, budgets, backups and real deployment remain external owner-approved operations. No exposed SSH service is supplied by the application containers. Publication/deploy/rollback workflows remain opt-in with reviewed manifests, protected environment preflight before/after human approval and signed identity verification. Local smokes do not prove real AWS, production cutover, ACME issuance, zero downtime or an RTO/RPO target.

## Recorded evidence and how to repeat it

| Verified path                           | Actual recorded run                                                                                                                                                                                                                                                                                                                                                                                       | Scope and limitation                                                                                                                     |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Production Pg/database + Redis          | [run 38415398249](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415398249), PR head 1d54bb7                                                                                                                                                                                                                                                             | Real HTTP/FPM/Pg/session/cache/queue/auth/Teams/assets; HTTP localhost fixture only. Repeat commands above.                              |
| Retained OCI rollback                   | [run 38411021459](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38411021459), source 237d81d                                                                                                                                                                                                                                                              | True retained manifest/config digests, HTTPS assets, process reload, persistent data and failed-promotion recovery. Sanitized JSON only. |
| Complete application upgrade/rollback   | [run 38415398087](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415398087), source 1d54bb7                                                                                                                                                                                                                                                              | Real PostgreSQL + HTTPS + original auth cookie + additive schema + queue; simulated test-only candidate overlay, no AWS.                 |
| Controlled migration/process/safeguards | [migrations 38415398250](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415398250), [processes 38415397893](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415397893), [runtime 38415397928](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415397928) | Completed synthetic fixtures; process-only case is SQLite, migration/runtime cases Pg.                                                   |
| HTTPS transport                         | [run 38415398088](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/actions/runs/38415398088)                                                                                                                                                                                                                                                                              | Real local TLS/session/renewal fixture; no external ACME issuance.                                                                       |
| Logical backup/restore                  | [recovery guide](../infra/aws/recovery.md)                                                                                                                                                                                                                                                                                                                                                                | Disposable logical Pg/new-volume restore evidence; AWS snapshot/PITR/EBS restoration and RTO/RPO remain unproven.                        |

Workflow files identify the exact canonical commands and required target/context. A link to YAML or a successful config render is not evidence of execution. Read the selected run's immutable source SHA, completed checks and sanitized evidence; disabled publication/AWS/manual jobs are not passes for those operations. This guide's PR records its actual repeat runs and offline command validation; it changes documentation only.
