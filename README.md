# Laravel Vue Modular Architecture Example

Public reference implementation of a Laravel 13 + Vue 3/Inertia 3 modular monolith using Fortify, Wayfinder, pragmatic Clean Architecture, DDD, and lightweight CQRS. The example is a working Teams flow; there is no command bus, event store, separate database, or sample CRUD added solely for the architecture.

The source is available to study; this repository does not grant a license to reuse or redistribute it. Its dependencies retain their own licenses.

## Requirements and first install

The committed lockfile requires PHP 8.4.1 or newer for both runtime and development dependencies; PHP 8.5 is used in CI. Install SQLite PDO, Composer 2, Node 22.18 or newer in the 22.x series, and npm. The lockfiles select Laravel 13.35, Fortify 1.41, Inertia Laravel 3.5, Pest 5.3, and Vite 8. Use `composer show --direct` and `npm ls --depth=0` for the exact installed versions on your machine. PostgreSQL, `pdo_pgsql`, `pcntl`, and `posix` are needed to run the concurrency suite locally. Validation of the minimum PHP version is part of the template publication checklist; an install on PHP 8.5 alone does not prove that minimum.

```bash
composer install --no-interaction --prefer-dist
npm ci
cp .env.example .env
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction # no demo accounts or teams
npm run build
composer run dev
```

Configure `DB_CONNECTION` and its credentials in `.env` before migrating. The default local SQLite database can be created with `touch database/database.sqlite` if it is absent. `composer setup` is an alternative first-install script, uses `npm ci`, and runs key generation and migrations without interactive prompts. Do not point test or migration commands that reset schema at a database containing user data.

Installation and standard seeding create no predefined credentials. For a disposable local demonstration only, explicitly run:

```bash
php artisan db:seed --class=DemoDatabaseSeeder --no-interaction
```

This opt-in creates the verified account `test@example.com` with password `password` and its personal team. The composite and individual demo seeders reject every environment except `local` and `testing`, including when `--force` is supplied. They do not remove demo accounts that already exist: review previously seeded installations before using them outside development. Do not label a production installation `local` to bypass this protection.

`composer dev` runs the PHP server, queue listener, log viewer, and Vite. `.env.example` uses the log mailer, so invitation and password-reset messages appear in the application log during local development.

## Existing behavior

Fortify provides login, logout, password reset, email verification, two-factor authentication, passkeys, and password confirmation. Profile settings can update the account or delete it. Account deletion is rejected while the account owns a shared team or a personal team with other members; it never transfers ownership or deletes shared-team data. If memberships change outside the protected deletion snapshot, the operation is rejected without logout or partial cleanup. Invitation responses require verification of the current email, including direct handler calls. Teams supports personal teams, shared teams, member roles, invitations, explicit team switching, and the dashboard. A team URL supplies request context but does not overwrite the saved selection during GET or HEAD navigation. Current-team reads require an active team and membership; an inconsistent stored pointer is ignored, not repaired by a query. Users with no team are sent to the existing teams index. Vue/Inertia pages and generated Wayfinder route functions remain in place. Public registration is disabled in `config/fortify.php`; local demo data requires the explicit command above. Existing HTTP contracts remain in place apart from these documented security and consistency corrections.

## Modules

| Module   | Owns                                                                                                            | Public integration                                                                                                                      |
| -------- | --------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| Identity | `users`, `current_team_id`, authentication account model, password reset adapter, passkeys and two-factor state | Read-only `UserDirectory`, `SecurityAccounts`; actor-scoped `ProfileAccounts`, `AccountCredentials`; Teams-only `TeamSelectionAccounts` |
| Profile  | Profile update and deletion use cases, validation, settings HTTP routes                                         | Calls Identity `ProfileAccounts` and the Profile-only Teams `AccountTeamLifecycle` API                                                  |
| Security | Password-change use case and security settings HTTP routes                                                      | No cross-module API; calls Identity `AccountCredentials` and `SecurityAccounts`                                                         |
| Teams    | `teams`, `team_members`, `team_invitations`, team rules and use cases, notifications                            | `TeamContext` for read-only context; Profile-only `AccountTeamLifecycle`; `TeamQueries` is an internal read port                        |

The module tree is `app/Modules/<Module>/{Domain,Application,Infrastructure,Presentation}` with `routes/` where a module owns routes. `Application/Api` contains exports; `Application/Contracts` contains internal persistence and effect ports. The classes in `Application` are not all public. Identity's profile operation protects email verification atomically, while Teams handlers explicitly coordinate current-team selection with Identity. Team names are also protected in the create/update use cases, so CLI, jobs, and integrations cannot bypass that invariant. See [ARCHITECTURE.md](ARCHITECTURE.md) for ownership, allowed dependencies, transaction boundaries, deletion policy, and a complete invitation flow.

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

`composer test` includes Pint, PHPStan, and the PHP suite. `composer ci:check` adds frontend formatting/lint, an npm security audit at the high severity threshold, frontend type checking, and architecture checks. `composer coverage` enforces the repository's exact line and branch coverage gate; disable Composer's process timeout for this long-running command. CI runs coverage on demand through `workflow_dispatch`, not on every pull request. The workflow must be present on GitHub's default branch before manual dispatch is available; run the local gate before the initial integration and the manual CI gate before publication. The PostgreSQL concurrency suite requires a disposable PostgreSQL database, `pdo_pgsql`, `pcntl`, and `posix`; CI provisions PostgreSQL 16. A skipped concurrency test under SQLite is not evidence of a PostgreSQL pass.

After changing routes or controller names, run `php artisan wayfinder:generate --no-interaction` and `npm run build`. Do not edit generated Wayfinder files by hand. For a new module, follow the contract and testing steps in [ARCHITECTURE.md](ARCHITECTURE.md).

For PostgreSQL concurrency runs, configure a new empty disposable database, run migrations, then run the suite above with that connection. These tests commit across independent connections and leave fixtures behind; repeating them against the same populated database can collide with earlier fixtures. Never reset a database containing application data to run this suite.
