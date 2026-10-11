# Architecture

This repository is being migrated incrementally to a modular Laravel monolith. The existing Laravel, Vue/Inertia, database, Fortify, and public HTTP contracts remain the compatibility surface while modules are moved one vertical slice at a time.

## Current migration status

`Teams` is the first complete module. Its routes, HTTP adapters, Eloquent models, factories, migrations, notification, policy, validation rules, and provider are under `app/Modules/Teams`.

Completed Teams write slices are **create team**, **team lifecycle** (update, switch, leave, delete), **membership management**, and **invitations** (create, cancel, accept, decline, and expiry pruning).

`HTTP/Inertia request → command/handler → Application contract → Eloquent adapter → database`

The public routes retain their validation, authorization, redirects, flash messages, session behavior, team slug generation, membership creation, current-team update, invitation handling, and scheduled pruning. No generic repository or command bus was introduced.

`TeamQueries` is the public read API currently used by the dashboard, Inertia shared props, Fortify login invitation context, and post-authentication redirects. It returns immutable data objects rather than Eloquent models.

`Profile` contains explicit update and deletion commands. `Security` contains the password-update command. Their HTTP controllers remain compatibility adapters in the existing settings route tree.

## Module boundaries

```text
app/Modules/Teams/
  Domain/                 Framework-free rules and enums
  Application/            Commands, immutable data, public contracts
  Infrastructure/         Eloquent persistence, factories, migrations, provider
  Presentation/           HTTP controllers, requests, middleware, policy, rules
  routes/                 Route registration
```

Domain must not depend on Application, Infrastructure, Presentation, Laravel, Inertia, or SDKs. Application depends only on Domain and its own contracts/data. Infrastructure implements Application contracts. Presentation is the HTTP adapter.

The explicit compatibility exceptions are the global `User` adapter, Laravel route model binding/policies inside Teams presentation, and `App\\Actions\\Fortify\\ResetUserPassword`, which must implement Fortify's framework contract. They are deliberately narrow and remain covered by feature tests. No module exposes Eloquent models in its Application contracts.

## Verification

```bash
composer architecture
php artisan test --testsuite=Integration
composer test
composer coverage
npm run check
npm run build
```

`tests/Architecture/TeamsArchitectureTest.php` checks the Domain and Application dependency directions, contract/data hygiene, and prohibited debug helpers for the migrated modules. It is intentionally scoped; legacy application code is not hidden through broad namespace exclusions.

## Next slices

Fortify's own reset-password action, passkeys, and two-factor lifecycle remain framework adapters. The global authentication model remains an explicit compatibility boundary. Future modules should be added from existing capabilities, each with architecture rules and behavior coverage before their migration.
