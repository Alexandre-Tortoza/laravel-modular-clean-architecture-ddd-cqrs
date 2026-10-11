# Architecture

This starter kit is one Laravel application with two business modules. HTTP routes and the Vue/Inertia pages keep their existing contracts. Commands run synchronously through dependency injection; reads return projections. There is no command bus, event store, separate database, or generic repository layer.

## Modules and data ownership

| Module     | Owns                                                                                                                           | Public application API                                                             |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| `Identity` | `User`, authentication, profile, password and security settings; `users` and passkey/two-factor user data                      | `Application/Api/UserDirectory` and immutable `UserRecord`                         |
| `Teams`    | Teams, memberships, invitations, roles, personal teams and current-team selection; `teams`, `team_members`, `team_invitations` | `Application/Api/TeamContext`, with the data and enum types used by its signatures |

`users.current_team_id` stays in the existing `users` table, with its existing Teams migration. Teams owns its meaning and updates it through its persistence adapter. Identity does not decide which team is current. The original users migration also creates the technical `sessions` table; its location does not make sessions an Identity domain concept. Cache and jobs tables remain global technical infrastructure.

Teams depends on Identity's `UserDirectory` for user lookup and ordered locking, receiving IDs and immutable user data rather than a `User` model. Identity has no dependency on Teams. Global Fortify and Inertia adapters compose identity and team data through the exported Teams API. Laravel's `config/auth.php` names the Identity Eloquent user model because the auth provider requires a concrete class. The global provider list and database seeder are composition points that name module providers and seeders; they are not cross-module domain dependencies.

## Layout and dependency rules

```text
app/Modules/<Module>/
  Domain/                   Pure roles, invariants, policies, business exceptions
  Application/
    Api/                    Explicit exports for other modules and global adapters
    Commands/               One readonly command and one handler per write case
    Queries/                Read use cases where a separate query is useful
    Contracts/              Internal persistence, clock, notification, transaction ports
    Data/                   Read projections and application data
  Infrastructure/           Eloquent models, factories, repositories, queries,
                            migrations, seeders, notifications and provider
  Presentation/             HTTP controllers, requests, middleware, policy adapters
  routes/                   Module-owned route definitions
```

Only create directories when code needs them. Domain uses PHP and domain types only. Application coordinates use cases, authorization, transactions, persistence ports and external effects; it never imports Eloquent, HTTP or Infrastructure. Infrastructure implements internal ports, owns database mapping and locking, and can use framework services. Presentation turns HTTP input into commands, renders Inertia data, and maps exceptions to HTTP responses. A module can import another module's `Application/Api` exports and the concrete value types named by those exports; it cannot import the other module's internal commands, handlers, contracts, models, factories or controllers.

Models and their Eloquent factories live under the owner's `Infrastructure/Persistence/Eloquent`. Identity's user factory creates only a user. The existing user-with-personal-team scenario is composed explicitly in test support and seeders. `DatabaseSeeder` invokes the demo user and personal-team seeders, which preserve the existing `test@example.com` scenario. Each module provider binds its ports and registers its own migrations, routes and policies. The Laravel bootstrap composes the providers; there is no custom module discovery runtime.

## CQRS through a real Teams flow

**Write:** `PATCH settings/teams/{team}/members/{user}` reaches `TeamMemberController`, whose request validates the role and whose HTTP policy checks the actor. The controller creates a readonly `UpdateTeamMemberRole` and calls `UpdateTeamMemberRoleHandler::handle()`. The handler opens the Teams transaction, reads the team and member state under the adapter's locks, and applies `TeamLifecyclePolicy::assertCanChangeRole` to the locked state. `ManagesTeams` is the internal write port; `EloquentTeamManager` maps and persists the role change. Presentation translates a business rejection to the existing HTTP response and redirects with the existing flash message. Authorization is repeated in the use case against the authoritative state, so a caller outside HTTP cannot bypass it.

**Read:** `GET settings/teams/{team}` reaches `TeamController::edit()`, which calls the internal `TeamQueries::editFor()`. `EloquentTeamQueries` reads the database directly and returns `TeamEditData` and immutable team projections. The controller shapes the existing Inertia `teams/Edit` props. No aggregate reconstruction or write occurs in this query. The public `TeamContext` is narrower: global adapters use it for the selected team, login invitations and redirects without seeing Eloquent.

Commands are named for the case, for example `UpdateTeamMemberRole` and `UpdateTeamMemberRoleHandler`. Queries need a DTO only when data or a boundary calls for one. Internal `Application/Contracts` ports are injected directly; they are not exports for other modules to write into this module. Domain rules are shared by the relevant write handlers, not copied into controllers or each adapter.

Team write handlers own transactions. Adapters perform SQL and preserve the lock order required by the current flows, including team then user IDs in ascending order where both are locked. Decisions that depend on persisted state run after those reads, inside the transaction. Invitation commands and queries share the same clock and expiry boundary. The notification adapter schedules invitations after commit; rollback does not send them. These are properties of the current implementation, not an outbox or an additional delivery guarantee.

## Adding a module and verifying it

1. Start with a real use case and determine which module owns its data and rules. Add `Domain`, `Application`, `Infrastructure`, `Presentation` and `routes` files only as needed.
2. Put a readonly command and dedicated handler around each write. Keep pure decisions in Domain; expose internal persistence and effect ports from Application and implement them in Infrastructure. Add a read adapter that returns projections when a screen needs data.
3. Export only the needed contract and value types from `Application/Api`. Register bindings, migrations, routes and policies in the module provider, then add that provider to `bootstrap/providers.php`. Compose cross-module seed scenarios outside a factory.
4. Add Domain unit tests without Laravel boot, handler tests with port doubles, adapter and HTTP/Inertia regression tests, and a real PostgreSQL concurrency test if the new behavior depends on locks. The architecture suite discovers `app/Modules/*` automatically and detects forbidden imports even in a newly added module.

```bash
composer architecture
php artisan test --compact --testsuite=Unit
php artisan test --compact --testsuite=Integration
php artisan test --compact --testsuite=Feature
composer test
composer coverage
npm run check
npm run types:check
npm run build
```

The Teams role-change path is covered by Domain policy tests, handler/adapter tests, and HTTP team-member tests. `TeamQueries::editFor()` has integration and HTTP/Inertia coverage. The `Concurrency` suite requires a disposable PostgreSQL database with `pdo_pgsql` and `pcntl`; `.github/workflows/tests.yml` runs it in a separate PostgreSQL job. Run migration and seed checks against a disposable database, including clean install and upgrades from an earlier state. Architecture checks enforce static source boundaries; behavioral and database tests verify execution.
