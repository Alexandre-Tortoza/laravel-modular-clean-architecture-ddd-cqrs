# Laravel modular monolith starter kit

A working Laravel and Vue/Inertia starter kit organized by module, with pragmatic Clean Architecture, DDD, and synchronous CQRS. It provides the existing identity and team flows as examples for starting an application; it does not prescribe a fictional business domain.

## What is included

- **Identity:** user, login, profile, password, email verification, two-factor authentication, and passkeys through the installed Fortify integration. Registration is not enabled.
- **Teams:** personal and shared teams, current-team selection, membership roles, invitations, and the existing team settings pages.
- **Frontend:** Vue/Inertia pages with Laravel Wayfinder-generated route functions.

The two modules live in `app/Modules/Identity` and `app/Modules/Teams`. The framework bootstrap, shared Inertia and Fortify adapters, and technical cache/job tables remain outside the modules. [ARCHITECTURE.md](ARCHITECTURE.md) explains ownership, the public APIs, dependency rules, and a complete Teams write/read flow.

## Install from a fresh clone

Use PHP 8.3 or newer (CI uses 8.5), Composer 2, and Node 22. The installed Laravel major version is 13. SQLite is the default local database; use a new database for this setup.

```bash
composer install
npm ci
cp .env.example .env
touch database/database.sqlite
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
npm run build
composer run dev
```

The seeders create `test@example.com` with the factory password `password` and a personal team for local exploration. Configure `APP_URL`, mail, and any non-default database settings in `.env` for your environment. Do not use the demo credentials outside a disposable development environment.

## Verify and extend

```bash
composer architecture              # dynamically discovered module boundaries
composer test                      # formatting, PHPStan, and PHP tests
composer coverage                  # branch coverage and project coverage gate
npm run check                      # frontend lint/format check
npm run types:check                # Vue and TypeScript types
npm run build                      # production assets and Wayfinder generation
```

`composer ci:check` runs the local CI checks. PostgreSQL concurrency tests need a dedicated disposable PostgreSQL database with `pdo_pgsql` and `pcntl`; CI runs them in its separate `postgres-concurrency` job. Never run `migrate:fresh` against an existing database you intend to keep.

For a new module, start with a real use case and follow the module conventions in [ARCHITECTURE.md](ARCHITECTURE.md). Keep routes, Eloquent resources, and providers with their owner; export only the contracts and data another module actually needs. Add behavior and boundary tests before extending the convention.

The project retains its original MIT license declaration in `composer.json` and the original starter-kit authorship by Alexandre Tortoza.
