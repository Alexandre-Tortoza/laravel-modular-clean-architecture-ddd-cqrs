## Change
Explain the problem and resulting behavior. Link the issue: Closes #

## Validation
List commands actually executed, results and CI links. Explain skipped checks or unavailable infrastructure.

Review the applicable checks in [tests.yml](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/blob/dev/.github/workflows/tests.yml): `ci`, `minimum-php`, `postgres-concurrency`, and `sail`. The exact coverage gate is run on demand for behavior changes; do not weaken it. Production-image changes also require `build-and-smoke` in [php-image.yml](https://github.com/Alexandre-Tortoza/laravel-modular-clean-architecture-ddd-cqrs/blob/dev/.github/workflows/php-image.yml).

## Risks and dependencies
Compatibility, data and operational risks; prerequisite PRs and the commits belonging to this issue if the branch is stacked.

- [ ] Semantic branch (`feat/10-example`, `fix/10-example`, `test/10-example`, `build/10-example`, `ci/10-example`, `chore/10-example` or `docs/10-example`); one issue per PR to `dev`.
- [ ] Acceptance criteria satisfied, applicable checks passed, and no secrets or sensitive artifacts committed. Keep the PR Draft otherwise.
- [ ] Human review controls integration. Promotion from `dev` to `main`, publication and deploy require human authorization; this PR does not change GitHub Settings or rulesets.

CI uses restricted `pull_request` permissions without AWS credentials. Report infrastructure checks separately when their execution requires approved access.
