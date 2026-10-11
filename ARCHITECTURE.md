# Architecture

This repository is being migrated incrementally to a modular Laravel monolith. The existing Laravel, Vue/Inertia, database, Fortify, and public HTTP contracts remain the compatibility surface while modules are moved one vertical slice at a time.

## Current migration status

`Teams` is a complete module. Its routes, dashboard HTTP adapter, middleware, Eloquent models, factories, migrations, notification, policy, validation rules, and provider are under `app/Modules/Teams`.

Completed Teams write slices are **create team**, **team lifecycle** (update, switch, leave, delete), **membership management**, and **invitations** (create, cancel, accept, decline, and expiry pruning). Each write is represented by an explicit immutable command and handled through direct dependency injection; no command bus or mediator is used.

The write handlers declare their transaction boundary through `RunsTeamTransactions`; the Eloquent adapter implements that boundary while repositories only perform persistence and locking. Notifications are sent after the write completes, outside the database transaction.

`HTTP/Inertia request → command/handler → Application contract → Eloquent adapter → database`

The public routes retain their validation, authorization, redirects, flash messages, session behavior, team slug generation, membership creation, current-team update, invitation handling, and scheduled pruning. No generic repository or command bus was introduced.

`TeamQueries` is the public read API currently used by the dashboard, Inertia shared props, Fortify login invitation context, and post-authentication redirects. It returns immutable data objects rather than Eloquent models.

`Profile` contains explicit update and deletion commands, along with its structural validation rules. `Security` contains the password-update command. Their HTTP controllers remain compatibility adapters in the existing settings route tree.

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

The explicit compatibility exceptions are the global `User` adapter, Laravel route model binding/policies inside Teams presentation, the Inertia/Fortify adapters under `app/Http` and `app/Providers`, and `App\\Actions\\Fortify\\ResetUserPassword`, which must implement Fortify's framework contract. They are deliberately narrow and remain covered by feature tests. No module exposes Eloquent models in its Application contracts.

## Verification

```bash
composer architecture
php artisan test --testsuite=Integration
composer test
composer coverage
npm run check
npm run build
```

`tests/Architecture/TeamsArchitectureTest.php` and `tests/Architecture/ContractsArchitectureTest.php` check the Domain and Application dependency directions, module-boundary imports, contract signatures, model/factory placement, immutability, and prohibited debug helpers for the migrated modules. The Team Domain rule was proven with a temporary `Illuminate` dependency: it failed as expected, then the fixture was removed. The suite is intentionally scoped; framework adapters and legacy compatibility code are not hidden through broad namespace exclusions.

Architecture tests are static boundary checks, not proof that dynamic calls cannot bypass a boundary or that a write is correct. Feature and integration tests continue to validate HTTP behavior, authorization, database effects, factories, providers, and the scheduled invitation cleanup.

## Next slices

Fortify's own reset-password action, passkeys, and two-factor lifecycle remain framework adapters. The global authentication model remains an explicit compatibility boundary. Future application capabilities should be added from real code and receive behavior coverage plus architecture rules before their migration; they should not be created merely to fill the module tree.
