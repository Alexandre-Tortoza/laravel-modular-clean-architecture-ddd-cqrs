# Local development

Use Sail for PostgreSQL 16 development. AWS, Terraform, ECR and Redis are unnecessary for this path. Docker Engine/Desktop with Compose v2 and access to its daemon, Git and a POSIX shell are required; on Windows use WSL2. Initial installation needs network access to package and image registries. Once dependencies and images are available, application tests do not require AWS credentials.

The lockfile requires PHP 8.4.1 or newer. The committed Sail runtime uses PHP 8.4 and Node 22; CI also tests PHP 8.5 and exactly 8.4.1. The host alternative needs Composer 2.8+, Node 22.18+ in the 22.x series and the PHP extensions required by `composer check-platform-reqs`. See the [locked requirements](../composer.json), [Sail configuration](../compose.yaml) and [CI acceptance](../.github/workflows/tests.yml).

## New checkout with Sail

Clone a reviewed branch containing this guide into a new directory. During roadmap review the remote `dev` branch does not yet contain the stacked implementations; select the reviewed PR head explicitly. For an installed template, use the application's own approved branch.

```bash
git clone https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs.git modular-template
cd modular-template
git switch YOUR_REVIEWED_BRANCH
./sail-bootstrap
./vendor/bin/sail build
./vendor/bin/sail up --detach --wait --wait-timeout 120
./vendor/bin/sail composer setup
./vendor/bin/sail artisan db:seed --no-interaction
```

Replace `YOUR_REVIEWED_BRANCH` before execution. `sail-bootstrap` installs the committed Composer lockfile through a pinned official Sail Composer image, so host PHP/Composer are unnecessary. It copies `.env.sail.example` only if `.env` is absent, preserves existing files and propagates Docker failures. It does not start containers, migrate, seed or generate a key. Installation scripts can generate ignored framework discovery files.

If using host Composer instead, create the Sail environment before starting Sail:

```bash
composer install --no-interaction --prefer-dist
test -e .env || cp .env.sail.example .env
```

An existing `.env` wins: inspect it before changing database engines. Copying configuration does not convert or migrate existing SQLite/PostgreSQL data. Default `sail` credentials belong only to the disposable local database.

`composer setup` installs locked Composer/npm dependencies, clears configuration cache, runs the idempotent `app:ensure-key`, migrates the selected database and builds assets. It preserves an existing `APP_KEY`; keep that key to retain encrypted data and sessions. It is a local bootstrap command, not a production deploy command. Standard seeding creates no accounts or teams. Provision an account with `./vendor/bin/sail artisan app:create-first-user`; its password is hidden and confirmed interactively, never passed on the command line.

Open the `APP_URL` configured in your local environment. Its example is `http://localhost:8000`; `APP_PORT` controls the web port. For live frontend development run `./vendor/bin/sail npm run dev` in another terminal. The development server uses `VITE_PORT` (default 5173). The web/Vite mappings can be reachable from your host's network; use this stack on a trusted development machine, not a public server. PostgreSQL's forwarded port is explicitly bound to `127.0.0.1`.

## Ports, storage and normal shutdown

Before starting, edit `.env` if ports collide: change `APP_PORT` and the matching `APP_URL`, `FORWARD_DB_PORT` for host database access, and `VITE_PORT` for Vite. Container-to-container PostgreSQL remains `DB_HOST=pgsql`, `DB_PORT=5432`; changing the forwarded host port does not change these values. Do not point it at a shared application database.

The `sail-pgsql` named volume stores PostgreSQL 16 data at `/var/lib/postgresql/data`. The checkout bind mount retains local uploads and `.env`. Keep the Compose project name stable to reconnect to its volume. Normal stopping/recreation preserves those files and the database:

```bash
./vendor/bin/sail stop
./vendor/bin/sail up --detach --wait --wait-timeout 120
./vendor/bin/sail down
./vendor/bin/sail up --detach --wait --wait-timeout 120
```

**`docker compose down -v` (or `sail down -v`) deletes project volumes and therefore the local PostgreSQL data.** Do not use it for routine restarts, troubleshooting, upgrades or test isolation. The local bootstrap/setup does not remove volumes implicitly. The hosted runtime fixtures elsewhere in this repository clean only their own disposable resources; do not reuse their cleanup commands on an application stack. Changing a project name hides the old volume from the new stack; it does not transfer data. Back up data before any intentional removal.

## Migrations, accounts and demonstration data

Run migrations explicitly against the selected development connection:

```bash
./vendor/bin/sail artisan migrate --no-interaction
./vendor/bin/sail artisan migrate:status --no-interaction
./vendor/bin/sail artisan db:seed --no-interaction
```

Do not run `migrate:fresh`, `db:wipe` or rollback commands on a database containing user data. Do not rotate `APP_KEY` to fix a startup issue. The optional demonstration is for an expendable local/testing environment only:

```bash
./vendor/bin/sail artisan db:seed --class=DemoDatabaseSeeder --no-interaction
```

It creates `test@example.com` / `password` and a personal team. Composite and module demo seeders reject other environments, including with `--force`; changing `APP_ENV` to bypass that guard is unsafe. They do not remove already seeded accounts. Standard seeding and migrations never opt into the demo. The local `log` mailer writes invitation/password-reset content into local application logs: keep those logs private and configure an appropriate transport in a derived application.

## Tests and database isolation

For the usual suite, leave database overrides out of the process environment:

```bash
./vendor/bin/sail artisan config:clear --no-interaction
./vendor/bin/sail test --compact
./vendor/bin/sail composer architecture
./vendor/bin/sail composer ci:check
./vendor/bin/sail npm run build
```

[`phpunit.xml`](../phpunit.xml) selects SQLite `:memory:`, array cache/session and a synchronous queue. Its defaults keep application data out of the ordinary suite. Some PostgreSQL concurrency and explicit Redis/TLS fixture tests skip unless their required environment is supplied; a skip does not prove that backend passed. `composer ci:check` adds formatting, static analysis, architecture, frontend types and dependency audits. Build generates Wayfinder outputs needed by frontend types in a fresh checkout. Configuration caches can override test settings: clear them before tests and keep local configuration uncached.

For actual PostgreSQL integration/concurrency use only the empty `testing` database created by Sail's PostgreSQL initialization script in a **new disposable project**. The script runs on first volume initialization; an older volume may not have that database. Never redirect these commands to `laravel` or a shared database. External `DB_URL` can take precedence over connection fields, so explicitly clear it along with the process overrides below.

In Bash, this helper repeats the exact isolation settings for each command:

```bash
pgtest() {
    docker compose exec -T --user sail \
        --env APP_ENV=testing --env DB_CONNECTION=pgsql --env DB_URL= \
        --env DB_HOST=pgsql --env DB_PORT=5432 --env DB_DATABASE=testing \
        --env DB_USERNAME=sail --env DB_PASSWORD=sail \
        laravel.test php artisan "$@"
}

# Check before the first migration, on the newly initialized disposable stack.
docker compose exec -T pgsql psql -U sail -d testing -Atc \
    "SELECT to_regclass('public.migrations') IS NULL"
pgtest migrate --force --no-interaction
pgtest test --compact --testsuite=Integration
pgtest test --compact tests/Feature/Database/DatabaseSeederCompositionTest.php tests/Feature/InfrastructureBaselineTest.php
pgtest test --compact --testsuite=Concurrency
```

The initial query must return `t`; if it returns `f`, inspect the database rather than resetting it. PostgreSQL tests use separate connections, `pdo_pgsql`, `pcntl` and `posix`; Sail provides these. Concurrency tests commit fixtures across connections and can leave data behind. Do not repeat against a populated test database expecting a pristine run: use a fresh disposable checkout/project/database for a new rehearsal. No command above resets development schema. CI also checks the development persistence marker and that its users/teams stay empty after these tests.

For exact line/branch coverage, pass the timeout and Xdebug settings into the container explicitly; a host variable before `sail composer` is not forwarded automatically:

```bash
docker compose exec -T --user sail \
    --env COMPOSER_PROCESS_TIMEOUT=0 --env XDEBUG_MODE=coverage \
    laravel.test composer coverage
```

Sail's PHP runtime includes Xdebug. This gate is expensive and CI runs it on explicit dispatch. Do not weaken coverage or count an ordinary PR's skipped coverage job as a coverage pass.

## Optional Redis, independently per purpose

Defaults are `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`; the `redis` service is excluded unless its profile is enabled. Start it explicitly:

```bash
./vendor/bin/sail --profile redis up --detach --wait --wait-timeout 120 redis
docker compose --profile redis exec -T redis redis-cli ping
```

Expect `PONG`. Keep `REDIS_HOST=redis`, port 6379 inside Sail. Its optional forwarded port is localhost-only and configurable with `FORWARD_REDIS_PORT`. This local Redis has no configured password/TLS or retained volume; it is unsuitable for public exposure or durable production queues.

Choose each purpose separately in `.env`, then clear configuration cache:

| Setting            | Database default          | When Redis is appropriate                                                                                                |
| ------------------ | ------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `CACHE_STORE`      | Persistent cache table    | Disposable computed data with TTL; choose `redis` only with a reachable service.                                         |
| `SESSION_DRIVER`   | Persistent sessions table | Shared session storage with an explicitly operated Redis lifecycle; clearing Redis can log users out.                    |
| `QUEUE_CONNECTION` | Persistent jobs table     | Asynchronous Redis jobs with separately operated workers and retention/recovery; `sync` runs immediately in the request. |

```bash
./vendor/bin/sail artisan config:clear --no-interaction
./vendor/bin/sail artisan queue:work --sleep=1 --tries=1 --timeout=60
```

Run the worker in another terminal only when consuming the chosen asynchronous queue. Restart long-lived workers after changing drivers/code; the existing `queue:restart` signal uses the configured cache. Enabling the Redis profile changes no Laravel driver; selecting Redis does not start it. When a selected Redis connection fails, operations throw instead of silently switching to the database. Before stopping Redis, restore **every selected** driver to its intended supported backend and restart the affected processes. Never silently abandon queued jobs or sessions when switching backends.

The actual opt-in Redis test uses a disposable PostgreSQL `testing` database, Redis database 15 and a random prefix. It tests real cache TTL/invalidation/isolation and explicit connection failure while sessions/queue stay database-backed:

```bash
docker compose exec -T --user sail \
    --env RUN_REDIS_TESTS=1 --env APP_ENV=testing \
    --env CACHE_STORE=redis --env SESSION_DRIVER=database --env QUEUE_CONNECTION=database \
    --env REDIS_HOST=redis --env REDIS_URL= --env REDIS_CACHE_DB=15 \
    --env REDIS_PREFIX="redis-tests-$(cat /proc/sys/kernel/random/uuid)-" \
    --env DB_CONNECTION=pgsql --env DB_URL= --env DB_HOST=pgsql --env DB_PORT=5432 \
    --env DB_DATABASE=testing --env DB_USERNAME=sail --env DB_PASSWORD=sail \
    laravel.test php artisan test --compact --testsuite=Redis
```

This example assumes Linux/WSL2's UUID source. Never flush a shared Redis database for tests. Stop the optional service after restoring drivers: `./vendor/bin/sail --profile redis stop redis`.

## Troubleshooting and evidence

| Symptom                                              | Check                                                                                                                                                                                        |
| ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Missing `vendor/bin/sail`                            | Run `./sail-bootstrap` from the checkout with its Composer lockfile; examine its nonzero status before continuing.                                                                           |
| Docker daemon unavailable                            | Verify Docker is running and that your account has access. Changing socket permissions or running application setup as root is not a fix supplied by this guide.                             |
| Port already allocated                               | Change the corresponding forwarded port before startup; keep internal database/Redis ports unchanged.                                                                                        |
| PostgreSQL authentication fails after editing `.env` | Existing volumes retain their original database/user/password. Changing environment variables does not rewrite stored credentials. Inspect/reconcile deliberately; do not delete the volume. |
| Tests use the wrong connection                       | Clear configuration cache, inspect inherited `DB_*`/`DB_URL` variables and use only the explicit disposable test settings.                                                                   |
| Vite manifest missing or frontend types missing      | Run `./vendor/bin/sail npm run build`; use `npm run dev` for active frontend development.                                                                                                    |
| Redis unavailable                                    | Check the profile, selected drivers and internal hostname; there is no automatic fallback.                                                                                                   |

Use `./vendor/bin/sail ps`, `./vendor/bin/sail logs`, `./vendor/bin/sail artisan migrate:status` and `./vendor/bin/sail artisan config:show database.default` for diagnosis. Logs can contain local mail/data: redact them before sharing; do not print `.env` or passwords.

The [Sail acceptance job](../.github/workflows/tests.yml) exercises a clean checkout without host PHP/Composer, repeated setup/bootstrap with unchanged key/lockfiles, PostgreSQL 16 persistence across `down`/`up` without volume deletion, HTTP health/login, isolated PostgreSQL integration/concurrency, independent Redis drivers and the real Redis negative case. It uses owned hosted-runner fixtures without AWS. For this guide's verification results and source revision, see its PR; configuration parsing or host SQLite tests alone are not evidence that Docker/PostgreSQL ran.
