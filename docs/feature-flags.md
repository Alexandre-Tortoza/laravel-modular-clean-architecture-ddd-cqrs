# Feature flags: deferred until an application has a concrete use case

The template does not add Laravel Pennant, feature tables, flag caches or a
generic flag abstraction. The current starter has no identified user/team rollout
or business kill switch that must operate independently of a release. Adding a
demonstration flag would invent behavior and a dependency without solving a
problem in this application. This decision satisfies the conditional outcome of
roadmap issue #54; it does not prohibit a derived application from making its own
evidence-based decision.

## Evidence in this repository

- [Composer requirements](../composer.json) and the [lockfile](../composer.lock)
  contain no `laravel/pennant` dependency. Application/configuration/routes contain
  no Pennant feature definitions or evaluation calls.
- Existing functionality is the Identity/Profile/Security/Teams starter flow
  described in [ARCHITECTURE.md](../ARCHITECTURE.md). There is no proposed alternate
  Teams behavior, cohort rollout or partially shipped business feature to gate.
- [Fortify configuration](../config/fortify.php) already selects global supported
  authentication features. This is configuration, with existing authentication
  protections; a generic flag service would not establish permission.
- Teams controllers retain their gates and the underlying application/domain
  policies. See [TeamController](../app/Modules/Teams/Presentation/Http/Controllers/TeamController.php),
  [TeamInvitationController](../app/Modules/Teams/Presentation/Http/Controllers/TeamInvitationController.php)
  and [TeamLifecyclePolicy](../app/Modules/Teams/Domain/Policies/TeamLifecyclePolicy.php).
  A flag must never substitute for membership, verified email or authorization.
- [Deploy](../.github/workflows/deploy.yml),
  [rollback](../.github/workflows/rollback.yml) and
  [image promotion](../.github/workflows/publish-images.yml) have explicit operational
  opt-ins and protected approval preparation. These select permitted operations;
  they are not user/team feature flags. The prepared
  [rollback helper](../infra/aws/scripts/rollback_release.py) restores a completed
  release receipt and does not reverse schema or external effects.
- Redis/Valkey and the [isolated runtime benchmark](../.github/workflows/runtime-benchmark.yml)
  remain optional infrastructure/measurement choices. They do not require a
  business flag service or a switch in every handler.

## Current fallback and boundaries

There is no new toggle to enable or disable. Existing starter behavior, HTTP
contracts and authorization remain the default. Database cache/session/queue
defaults and optional Redis remain unchanged; no dependency, table, cached
decision or cross-module API is introduced. Operational opt-ins remain disabled
until separately reviewed activation. This assessment does not activate a
workflow, run AWS, publish images or deploy an application.

## When to reconsider

A derived application can propose a flag when it has one named business behavior
whose availability must change independently of deployment, a documented reason
for that control, and a safe disabled fallback. Evaluate its existing native
configuration first. A scoped experiment needs a trusted user/team identity,
explicit authorization that remains effective in both states, and isolation
between actors/teams; URL input or a client-provided toggle cannot establish scope.

Only after that need is demonstrated should the owner approve an optional package
and its compatible installed version. Keep the integration at the affected use
case, default to the documented fallback, and test enabled/disabled behavior,
unauthorized access, cross-user/team isolation and unavailable decision storage.
Do not impose Redis, add flags to unrelated handlers/repositories, or add a
command bus/public abstraction for a hypothetical consumer. Define a removal
condition for the temporary flag.

This change records an architectural decision only. It adds no runtime behavior
requiring new behavioral tests and makes no claim of a Pennant integration or a
production rollout test. Repository references, formatting and inherited quality
checks validate the document; runtime coverage policy remains unchanged.
