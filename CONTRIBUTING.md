# Contributing

This MIT Laravel template accepts small, reviewed changes that preserve its modular contracts and existing starter behavior. Read [README.md](README.md), [ARCHITECTURE.md](ARCHITECTURE.md) and [AGENTS.md](AGENTS.md) before changing code. Report vulnerabilities according to [SECURITY.md](SECURITY.md), not in public issues.

## Branches and review

Start from the approved `dev` revision, preserve any existing work and create one semantic branch per issue, such as `feat/40-module-generator`, `test/41-module-scaffolding` or `docs/56-local-development`. Use Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `build:`, `ci:` or `chore:`). Open a PR with base **`dev`**, a concrete problem/result description, `Closes #N`, actual verification, material risks and any missing dependency or external activation. Keep unfinished work Draft.

Do not merge PRs or push shared `dev`/`main` automatically. During the current roadmap review, issue branches may stack original prerequisite commits through explicitly authorized local integration. Preserve their ancestry; identify the prerequisite PRs and the own review range in the PR description. Do not duplicate/cherry-pick/squash dependencies merely to make a broken dependent PR appear complete. The maintainer decides integration order and promotion to `main`.

Limit a change to its issue. Avoid unrelated formatting, dependency upgrades, architectural layers or generated output. Public HTTP/Inertia contracts, module ownership and the exact coverage gate are review constraints. Change dependencies or infrastructure topology only with explicit owner approval.

## Development and regression coverage

For a new clean Sail checkout, `./sail-bootstrap` installs locked Composer dependencies without host PHP; it preserves an existing `.env`. Then run:

```bash
./vendor/bin/sail build
./vendor/bin/sail up --detach --wait --wait-timeout 120
./vendor/bin/sail composer setup
```

Inspect the selected environment/database before setup or migrations. Setup is for local development, preserves `APP_KEY`, and does not seed demonstration accounts. Sail uses PostgreSQL 16; Redis is opt-in. The normal test suite uses SQLite in memory. Actual PostgreSQL concurrency requires the separate disposable `testing` database and explicit connection settings as exercised by [CI](.github/workflows/tests.yml). Never reset an application database to run tests or remove volumes to troubleshoot.

For behavior changes, write a meaningful regression test first, observe the expected failure, implement the smallest correction, then refactor with the test green. Cover authorization, validation and failure behavior when they belong to the changed contract. Pure copy/style changes need relevant examples/configuration/build checks, not artificial behavioral tests. Do not replace actual backend execution with mocks when the requirement concerns PostgreSQL, Redis or Docker runtime behavior.

The existing native generators are discoverable with `php artisan list`; check each command's `--help` before using it. Module commands are `make:module`, `make:module-usecase`, `make:module-query`, `make:module-seeder`, `make:module-artifact` and `make:module-command`. Generated pending tests and placeholder handlers must be implemented; generation alone is not a complete feature. Use `php artisan make:test --pest Name` for native feature tests.

## Quality gates

Use Sail prefixes below, or the same underlying commands on a correctly installed host environment:

```bash
./vendor/bin/sail artisan test --compact tests/Feature/InfrastructureBaselineTest.php
./vendor/bin/sail composer architecture
./vendor/bin/sail npm run build
./vendor/bin/sail composer ci:check
```

`ci:check` runs frontend format/lint and types, dependency audits, architecture, Pint, PHPStan and PHP tests. Run the narrow affected tests while editing and the applicable complete gates before marking a PR ready. For PHP changes, format with `vendor/bin/pint --dirty --format agent` (through Sail's `php` command if necessary). Regenerate changed backend routes with `php artisan wayfinder:generate --no-interaction`; never hand-edit ignored TypeScript route output. Building assets generates the outputs required by frontend types.

`COMPOSER_PROCESS_TIMEOUT=0 composer coverage` requires Xdebug and enforces **exact 100% lines and branches**, including the additional repository verifier. CI runs it on explicit dispatch; a skipped coverage job is not a pass. Preserve source inclusion and assertions; do not hide code, disable guards or lower thresholds. Native Docker/HTTPS/PostgreSQL/Redis fixtures and offline Terraform checks run where applicable. Tests and setup need package/image downloads initially; they need no AWS credentials. Record exact commands, revision and results, distinguishing skips, local checks and actual hosted execution.

## Versioning and derived applications

Template releases use reviewed stable `vMAJOR.MINOR.PATCH` identifiers. Patch changes should preserve documented contracts; minor versions add compatible capabilities; breaking changes require a major-version decision and explicit upgrade notes. Before 1.0, consumers must review every minor-version upgrade for compatibility and migration changes. This is a maintenance policy, not a claim that a release is already published.

The [release preparation workflow](.github/workflows/template-release.yml) validates a full tested `main` SHA, successful quality/exact coverage, immutable tag binding and reviewed changes/risks/upgrade notes. It emits a preview; it does not create a tag, publish or deploy. Initial `v0.1.0` additionally requires accepted #48/#49/#51/#55/#56/#58. Promotion to `main`, GitHub template activation and actual publication require maintainer review and separate authorization.

**GitHub template generation does not propagate upstream updates automatically.** A derived application owns its Git history, version, dependencies, infrastructure and business data independently. Record the template source revision, compare relevant upstream changes, adapt them in an application branch and run its tests/migration rehearsal before adoption. Do not overwrite `.env`, rotate keys, replay existing migrations or assume template version numbers identify your application's deployed artifacts. Retain applicable MIT and dependency notices.

## External operations

Do not commit credentials, `.env`, private keys, SQL dumps, private logs or complete OCI archives. Keep reproduction data synthetic and artifacts sanitized. `terraform apply/destroy`, AWS provisioning/secret reads, image publication, real deploy/rollback, DNS, GitHub settings and public release/tag creation are outside ordinary contribution verification and require explicit owner authorization. Prepared protected operations are disabled until their real identities, environment approvals, resources and compatibility are reviewed.
