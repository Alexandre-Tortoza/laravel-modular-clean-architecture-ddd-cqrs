# Laravel Modular Template

Free, reusable Laravel 13 + Vue 3/Inertia 3 template for a modular monolith with pragmatic Clean Architecture, DDD and CQRS. Identity, Profile, Security and Teams provide working starter authentication, settings and collaboration flows. Use it as a starting point for your own application; add the boundaries your business needs.

The [MIT license](LICENSE) permits use, modification and redistribution, including commercial use. Based on the [Laravel Vue starter kit](https://github.com/laravel/vue-starter-kit); its copyright notice is retained. Preserve applicable MIT and dependency notices when redistributing. Third-party packages retain their own licenses in their distributions; this project's license does not replace them.

## Requirements

| Path                          | Requirements                                                                                                                                                                           |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Recommended local development | Git, POSIX shell (WSL2 on Windows), Docker with daemon access and Compose v2. Sail supplies PHP 8.4, Node 22 and PostgreSQL 16; host PHP/Composer/npm are unnecessary.                 |
| Without Docker                | PHP **8.4.1+**, Composer **2.8+**, Node **22.18+ in the 22.x series**, npm and SQLite PDO. Run `composer check-platform-reqs` for required extensions. PHP 8.5 is the main CI version. |
| Production                    | Separate pinned PHP **8.5.10 FPM** and Nginx images; Compose **2.30+** for raw runtime env files. Read the production guide before operating real data.                                |

Lockfiles currently select Laravel 13.35.0, Fortify 1.41.0, Inertia Laravel 3.5.1 and Pest 5.3.1. Confirm installed versions with `composer show --direct` and `npm ls --depth=0`. Initial installation downloads dependencies/images. **AWS and Redis are unnecessary** for development or ordinary tests.

## Five-minute quickstart with Sail

This is the short path to a first result; downloads and image compilation can take longer than five minutes. Use a new directory. During roadmap review select a reviewed branch containing the implementation; remote `dev`/`main` are not automatically updated by these PRs. After integration use the approved branch/release. Replace `YOUR_REVIEWED_BRANCH` before execution:

```bash
git clone https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs.git my-app
cd my-app
git switch YOUR_REVIEWED_BRANCH
./sail-bootstrap
./vendor/bin/sail build
./vendor/bin/sail up --detach --wait --wait-timeout 120
./vendor/bin/sail composer setup
./vendor/bin/sail artisan db:seed --no-interaction
./vendor/bin/sail artisan app:create-first-user
```

Bootstrap installs locked Composer dependencies and copies `.env.sail.example` only when `.env` is absent. Setup installs locked dependencies, clears config cache, generates `APP_KEY` **only if missing**, migrates the selected database and builds assets. Existing configuration/keys are preserved. Standard seeding creates no accounts/teams. The final command provisions the first account and personal team using a hidden, confirmed password in an interactive terminal; there is no password CLI option and public registration is disabled by default.

Open the configured `APP_URL` (the local example is `http://localhost:8000`) and log in. Change `APP_NAME` and `VITE_APP_NAME` for your application's branding, then rebuild. Use `./vendor/bin/sail npm run dev` in another terminal for frontend development; configure ports before startup if they collide.

The first account starts with an unverified email. Before protected dashboard/Teams flows, request verification through the existing page and follow the link in the private local mail log; a deployed application needs its own approved mail transport.

PostgreSQL uses a named volume. Normal `sail stop`/`sail down` preserve it; **`down -v` deletes its data**. Never reset schema/volumes to troubleshoot a database containing application data. Setup is for local development, not production deployment.

## Without Docker: disposable SQLite development

Install the host requirements, clone/select the reviewed revision as above, then run in that new checkout:

```bash
test -e .env || cp .env.example .env
test -e database/database.sqlite || touch database/database.sqlite
composer setup
php artisan db:seed --no-interaction
php artisan app:create-first-user
composer run dev
```

The host example selects SQLite; an existing `.env` wins. Confirm the intended connection/database before setup or migrations. These commands do not convert existing data. `composer dev` starts the PHP server, queue listener, local log viewer and Vite. The log mailer writes local invitations/password-reset content to private application logs. PostgreSQL concurrency additionally needs `pdo_pgsql`, `pcntl`, `posix` and a separate disposable PostgreSQL database; use Sail and the development guide for that path.

For a **disposable local/testing demonstration only**, opt in explicitly:

```bash
# Host; use ./vendor/bin/sail artisan instead of php artisan with Sail.
php artisan db:seed --class=DemoDatabaseSeeder --no-interaction
```

This creates `test@example.com` / `password` and a personal team. Demo seeders reject other environments even with `--force`; they do not remove accounts already created. Keep demo credentials out of deployed applications and never relabel production as `local` to bypass the guard.

## Verification and tooling

Use these host commands, or prefix with Sail as appropriate:

```bash
php artisan list --no-interaction
php artisan route:list
php artisan app:ensure-key --no-interaction
php artisan migrate --no-interaction
php artisan test --compact
composer architecture
npm run build
composer ci:check
```

`ci:check` adds architecture, formatting, PHPStan, frontend types and dependency audits to PHP tests. The ordinary suite uses SQLite in memory; skipped PostgreSQL/opt-in cases are not backend passes. Clear configuration caches before tests. On the host, `COMPOSER_PROCESS_TIMEOUT=0 composer coverage` requires Xdebug and enforces exact 100% lines/branches. The development guide gives the explicit container environment for Sail. CI coverage is on demand, not an automatic PR guarantee; preserve its gates.

Module tooling: `make:module`, `make:module-usecase`, `make:module-query`, `make:module-seeder`, `make:module-artifact` and `make:module-command`. Check each command's `--help`; generated placeholders/pending tests need implementation. After route/controller changes run `php artisan wayfinder:generate --no-interaction` and build; do not edit generated TypeScript routes.

## Guides

| Guide                                                     | Use it for                                                                                          |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| [Local development](docs/development.md)                  | Sail, PostgreSQL persistence/test isolation, optional Redis and troubleshooting.                    |
| [Modular development](docs/modular-development.md)        | A tested module example, native generators, providers/migrations/seeders and public contracts.      |
| [Architecture](ARCHITECTURE.md)                           | Data ownership, transaction boundaries, restricted APIs and the complete Teams invitation flow.     |
| [Production runtime](docs/deployment.md)                  | Same-release PHP/Nginx assets, configuration, migrations, processes, readiness and Docker smoke.    |
| [AWS reference](docs/aws.md)                              | Opt-in EC2/Compose/private RDS, S3 state, IAM/SSM, HTTPS, costs and recovery limits.                |
| [Releases and rollback](docs/releases.md)                 | Immutable artifacts, compatible N/N-1 schema, retained-image recovery and backup rehearsals.        |
| [Contributing](CONTRIBUTING.md) / [Security](SECURITY.md) | Small PRs to dev, quality gates, derived-app updates and proposed private reporting channel status. |

Fortify provides login/logout, password reset, email verification, two-factor authentication, passkeys and password confirmation. Profile/Security own account/settings use cases; Teams supports personal/shared teams, member roles, invitations, explicit switching and the dashboard. Authorization/invariants remain enforced server-side. Cross-module access uses `Application/Api`; internal handlers, ports and Eloquent models are not exported. There is no generic command bus, event store or separate CQRS database.

Production publication/deploy/rollback are prepared, disabled opt-ins with human approval; no actual AWS, zero downtime or published `v0.1.0` is claimed. Optional Octane/FrankenPHP is a separate benchmark; PHP-FPM remains the default. The [feature flag assessment](docs/feature-flags.md) defers business flags until a concrete need exists.

Derived applications own independent Git history and release versions. GitHub template creation **does not propagate upstream updates automatically**. Adapt reviewed upstream changes deliberately, preserving keys, migration history, business data and applicable licenses. The GitHub “Use this template” setting still needs owner activation/promotion; cloning a reviewed revision is the current documented path.
