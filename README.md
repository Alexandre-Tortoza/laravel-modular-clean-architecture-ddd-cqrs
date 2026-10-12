# Laravel modular starter kit

Laravel 13 with Vue 3/Inertia 3, Fortify, Wayfinder, a modular backend, pragmatic DDD, and lightweight CQRS. The example is a working Teams flow; there is no command bus, event store, separate database, or sample CRUD added solely for the architecture.

## Requirements and first install

Use PHP 8.3 or newer with SQLite PDO (PHP 8.5 is used in CI), Composer 2, Node 22.18 or newer, and npm. The committed lockfiles select Laravel 13.35, Fortify 1.41, Inertia Laravel 3.5, Pest 5.3, and Vite 8. Use `composer show --direct` and `npm ls --depth=0` for the exact installed versions on your machine. PostgreSQL and `pdo_pgsql` are needed to run the concurrency suite locally.

```bash
composer install --no-interaction --prefer-dist
npm ci
cp .env.example .env
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction # optional demo user and personal team
npm run build
composer run dev
```

Configure `DB_CONNECTION` and its credentials in `.env` before migrating. The default local SQLite database can be created with `touch database/database.sqlite` if it is absent. `composer setup` is an alternative first-install script, but it uses `npm install`; the commands above use the lockfile exactly. Do not point test or migration commands that reset schema at a database containing user data.

## Existing behavior

Fortify provides login, logout, password reset, email verification, two-factor authentication, passkeys, and password confirmation. Profile settings can update the account or delete it. Teams supports personal teams, shared teams, member roles, invitations, team switching, and the dashboard. Vue/Inertia pages and the generated Wayfinder route functions remain in place. Public registration is disabled in `config/fortify.php`; a demo account is available only when the optional seed runs. Teams and invitation operations preserve their existing redirects, flash messages, middleware, authorization, and expiration behavior.

## Modules

| Module   | Owns                                                                                                            | Public integration                                                                                                                      |
| -------- | --------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| Identity | `users`, `current_team_id`, authentication account model, password reset adapter, passkeys and two-factor state | Read-only `UserDirectory`, `SecurityAccounts`; actor-scoped `ProfileAccounts`, `AccountCredentials`; Teams-only `TeamSelectionAccounts` |
| Profile  | Profile update and deletion use cases, validation, settings HTTP routes                                         | No cross-module API; calls Identity `ProfileAccounts`                                                                                   |
| Security | Password-change use case and security settings HTTP routes                                                      | No cross-module API; calls Identity `AccountCredentials` and `SecurityAccounts`                                                         |
| Teams    | `teams`, `team_members`, `team_invitations`, team rules and use cases, notifications                            | `TeamContext` for read-only context; `TeamQueries` is an internal read port                                                             |

The module tree is `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}` with `routes/` where a module owns routes. `Application/Api` contains exports; `Application/Contracts` contains internal persistence and effect ports. The classes in `Application` are not all public. See [ARCHITECTURE.md](ARCHITECTURE.md) for ownership, allowed dependencies, transaction boundaries, and a complete invitation flow.

## Development and verification

```bash
composer architecture
composer test
COMPOSER_PROCESS_TIMEOUT=0 composer coverage
composer ci:check
npm run check
npm run types:check
npm run build
php artisan route:list
php artisan test --compact --testsuite=Concurrency
```

`composer test` includes Pint, PHPStan, and the PHP suite. `composer ci:check` adds frontend checks and architecture checks. `composer coverage` enforces the repository's exact line and branch coverage gate locally; disable Composer's process timeout for this long-running command. The current CI workflow does not run coverage. The PostgreSQL concurrency suite requires a disposable PostgreSQL database, `pdo_pgsql`, and `pcntl`; CI provisions PostgreSQL 16. A skipped concurrency test under SQLite is not evidence of a PostgreSQL pass.

After changing routes or controller names, run `php artisan wayfinder:generate --no-interaction` and `npm run build`. Do not edit generated Wayfinder files by hand. For a new module, follow the contract and testing steps in [ARCHITECTURE.md](ARCHITECTURE.md).

The existing MIT license metadata remains in `composer.json`.
