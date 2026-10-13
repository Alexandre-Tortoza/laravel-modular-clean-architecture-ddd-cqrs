# Agent guidance

Read [README.md](README.md), [ARCHITECTURE.md](ARCHITECTURE.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md). Session instructions and explicitly authorized work take precedence. If `.ai/rules` exists, read its index and every rule covering the files before editing.

## Architecture

- Keep the modular monolith and pragmatic CQRS. Write use cases have immutable commands and dedicated handlers; call them directly. Do not add buses, event stores, repositories or empty interfaces without demonstrated need.
- Modules own their data and used Domain/Application/Infrastructure/Presentation layers. Domain is pure PHP; Infrastructure owns framework/persistence adapters; Presentation maps HTTP/console input and responses.
- Cross-module integration uses `Application/Api` and immutable types in its signatures. Internal contracts, handlers and Eloquent models are not public. Follow the restricted-consumer rules in the architecture tests.
- Preserve HTTP/Inertia behavior, authorization, migration history, starter features and stable application keys. Prefer existing native Laravel facilities and components.

## Commands and verification

- Discover installed package versions with `composer show --direct` and `package.json`; do not assume APIs. Use available version-aware framework documentation tools before ecosystem changes.
- Clean local Sail setup: `./sail-bootstrap`, `./vendor/bin/sail build`, `./vendor/bin/sail up --detach --wait --wait-timeout 120`, then `./vendor/bin/sail composer setup`. Inspect the selected environment first; no production setup or implicit demo seeding.
- Native generators: `php artisan list`, `php artisan COMMAND --help`. Use `make:module` and its `make:module-*` commands only when their generated artifacts are needed. Use `php artisan make:test --pest Name` for feature tests.
- Run affected tests with `php artisan test --compact PATH`; format PHP with `vendor/bin/pint --dirty --format agent`; run `composer architecture`, `npm run build`, `composer ci:check` and applicable runtime checks. Prefix commands with Sail as needed.
- Route changes: `php artisan wayfinder:generate --no-interaction` and frontend build; do not edit generated routes. Preserve the exact line/branch coverage gate: `COMPOSER_PROCESS_TIMEOUT=0 composer coverage` with Xdebug.
- Use meaningful Red → Green → Refactor for behavior changes; copy-only changes need relevant checks. Record actual outcomes, skips and limitations; never claim mock/SQLite success proves PostgreSQL/Docker/AWS execution.

## Autonomy boundaries

Make small, issue-scoped changes; preserve user work and follow sibling conventions. Use explicit types and existing components. No dependency changes, architecture rewrite, test removal, weaker assertions or coverage exclusions without authorization.

One semantic branch and PR per issue, base `dev`, `Closes #N`. Preserve original prerequisite ancestry when explicitly authorized to stack locally. Do not automatically merge PRs or push shared branches.

No `terraform apply/destroy`, provisioning, secret reads, image publication, real deploy/rollback, DNS/GitHub settings changes or public tags/releases without explicit owner authorization. No volume/schema reset on application data. Keep secrets, `.env`, private logs, dumps and image archives out of Git and public artifacts. Do not send messages to third parties without explicit authorization.
