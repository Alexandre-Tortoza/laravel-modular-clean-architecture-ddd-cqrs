# Releases, compatible migrations and recovery

A template version identifies reviewed starter source; a derived application owns its own version, source, deployment and data. Creating a project from a GitHub template does not propagate future updates. Compare/adapt upstream changes in application PRs and test them before adoption. A template release is not an application deployment.

Use stable `vMAJOR.MINOR.PATCH` identifiers and full Git source SHAs. The [template preparation workflow](../.github/workflows/template-release.yml) requires a tested `main` SHA, quality and exact coverage, immutable version binding and reviewed changes/risks/upgrade notes. Initial `v0.1.0` additionally requires accepted #48/#49/#51/#55/#56/#58. It creates a reviewable preview, never a tag/public release. Publication needs separate owner authorization; the current roadmap PRs are not an already published version.

## Artifact and release record

The [immutable producer](../.github/workflows/immutable-images.yml) builds PHP and Nginx from a shared BuildKit asset graph, once per selected source/version. The verified release identifies both OCI manifest digests, source revision, version, lockfile fingerprints, manifest and asset hashes. A Docker image configuration ID is distinct from an OCI distribution digest; do not substitute one for the other. Mutable tags such as `latest` are insufficient for promotion or rollback.

Promote the retained original OCI bytes with [publish-images.yml](../.github/workflows/publish-images.yml); do not rebuild between acceptance, staging and production. Verify registry digests and the matching PHP/Nginx asset set after transport. Runtime secrets/configuration remain external to images. Frontend build variables are public: never put credentials into them.

Ordinary PR builds keep full OCI archives on the disposable runner and upload only sanitized evidence. Complete archive retention is a separate protected owner opt-in, disabled by default. Its GitHub retention is 90 days; it is not a permanent recovery store. The prepared ECR repositories have immutable tags and no automatic lifecycle deletion. The operator must define, fund and review an explicit retention inventory: current release, compatible N-1, producer run, manifest/assets/configuration fingerprints, registry digests, public contract and approved source. Confirm the predecessor is still pullable before every deployment. Expiring a build artifact or deleting an ECR digest can invalidate rollback even when a tag/history entry exists.

## Preconditions before staging or production

Establish these independently for each intended environment:

1. Review source/tests, migrations, queue payload compatibility and all required native quality/runtime checks. Rehearse against representative staging data and configuration before authorizing production. The disposable fixture below is not proof that an actual staging environment exists.
2. Verify current and recoverable predecessor manifests/digests, retained configuration/Compose/runner hashes and the source/asset relationship. The target must use the matching PHP and Nginx images.
3. Confirm stable private `APP_KEY`, PostgreSQL connection/CA, retained uploads/EBS mount, HTTPS certificate/domain and backup recovery. Never replace the key as part of a release, embed secrets in requests or upload private state.
4. Inspect the host's completed current/previous and any pending state through an independently approved private operational channel. A completed pointer with pending state does not prove health.
5. Establish compatible N/N-1 schema and readable persisted/queued data. Approve this explicitly; `schema_policy:expand-contract` and `schema_compatible:true` record human decisions, not automated proofs.
6. Confirm the already existing protected GitHub environment, immutable repository/environment IDs, reviewers, no self-approval/admin bypass, approved source/main and narrowly scoped publisher/deployer identities. No environment is silently created. See [OIDC activation](../infra/aws/identity-activation.md) and [deployment activation](../infra/aws/deployment-activation.md).

The checked-in identities/deployment manifests are inactive. Publishing, EC2 deployment and rollback also require their respective owner-enabled variables and explicit manual inputs; PRs/pushes never authorize them. Preflight verifies protection before scheduling an environment and repeats identity/source checks after human approval. The exact signed JWT verified by the workflow is the token used for its separate role. Real AWS/STS/SSM, DNS and credential operations require separate authorization.

## Runbook: N → N+1

Use [deploy.yml](../.github/workflows/deploy.yml) only after the preceding owner approvals/activation. Select the exact protected producer `build_run_id`, full `source_revision`, stable `version`, `previous_run_id`, existing `environment` and reviewed deployment `role_arn`. `initial_release` is true only for a confirmed first release without a predecessor; it is not a way around missing N-1. `deploy` remains false until the owner authorizes the concrete operation.

The approved SSM document is bound to one instance, its numeric document version and AWS-produced Sha256. Do not use `$LATEST`, `$DEFAULT`, inferred hashes or an arbitrary shell document. GitHub serializes deployments/rollbacks per environment; the host shares a private lock and rejects stale current/previous identities.

The host promotion performs this order:

1. Validate retained images, provenance, public contract and unchanged persistent inputs; pull the selected digests.
2. Run the dedicated controlled migration command once per artifact under the database advisory lock. A migration failure leaves the completed current release running. A successful receipt avoids repeating its DDL on retry.
3. Record pending state, drain/stop old worker and scheduler with 90-second grace, start the candidate web pair and require trusted HTTPS `/ready`.
4. Start one worker and one scheduler using the same candidate PHP image, raw runtime environment, PostgreSQL CA and upload bind as web. Recheck readiness and actual processes before committing dated current/previous receipts.
5. Verify HTTPS liveness/readiness, actual asset hashes, authenticated application flow, persisted data, queue consumption and scheduler singleton. Retain only sanitized public identities/checks and timestamps.

Completed retries check health/processes without migrating or restarting. A failure after migration preserves completed receipts and a pending phase, stops an unready candidate when possible and requires explicit recovery. Stop further promotions until the actual state is understood. An SSM observation timeout is not evidence that the host command stopped: retain the public acknowledgment UUID/host, inspect that invocation privately and never blindly resend or cancel it.

## Runbook: restore N, or recover failed N+1

Application rollback restores retained images and their corresponding approved configuration. It does not run reverse migrations, restore database backups, delete volumes or undo external actions. Use [rollback.yml](../.github/workflows/rollback.yml) and the [exact request contract](../infra/aws/rollback.md) only after human schema/persistent-input confirmation and protected activation.

Construct the public `request_json` from the actual private host receipts, using exactly `schema_version:1`, `expected_current`, `expected_previous`, `expected_pending`, `target`, `target_contract_sha256`, `schema_compatible:true`. Each release identity contains `source_revision`, `version`, `php_image`, `nginx_image`, `manifest_sha256`, `assets_sha256`; no secrets or complete state file belong in it.

| Actual state                                     | Requested target | Required binding                                                                                   |
| ------------------------------------------------ | ---------------- | -------------------------------------------------------------------------------------------------- |
| N+1 completed, N retained                        | `previous`       | Exact current N+1, previous N, no unexpected pending candidate, N's recorded contract hash/source. |
| N+1 failed and pending, N remains last completed | `current`        | Exact last completed N and failed pending N+1, retained completed contract hash/source.            |

Never choose an uncompleted pending image as the recovery target. Supply the selected target's full source SHA, existing environment and separate approved deploy role; keep `rollback:false` until approved. The same exact hash/version-bound SSM document selects `Operation=rollback`, without expanding IAM.

Rollback holds the same host lock, checks history/request/configuration and persistent input hashes, pulls target digests, drains background processes, starts the retained web pair, validates HTTPS readiness, reloads its worker and singleton scheduler, and repeats readiness/process/public-asset smoke. It commits the completion timestamp and rollback/recovery identity only after these pass. An exact completed retry is read-only. Missing historical configuration, stale/tampered receipts, incompatible schema or changed persistent inputs fail closed.

If rollback itself is interrupted, preserve pending and completed receipts. Inspect actual containers, SSM invocation and data privately, establish compatibility and arrange a separately reviewed manual recovery; do not delete state/volumes to make a retry pass. Re-run the authenticated/queue/data smoke after recovery and record actual limits.

## Expand → migrate → contract

Expand with compatible nullable columns/tables/indices and APIs understood by both N and N+1. Rehearse the migration against separate disposable PostgreSQL data, including upgrade and old-code reads/writes. Migrate/backfill with explicit validation, bounded work and a resumable plan when needed; long-lived workers and already queued payloads also need coexistence compatibility.

Contract only after old web/workers are gone, data/backfill has been verified and the agreed rollback window is closed. Removing/renaming a used column or changing data representation can make N unusable: prepare a different recovery plan before authorizing that migration. `git revert` changes code; it does not reverse database rows, a payment already made or a message already delivered. Binary rollback, data restoration and business compensation are separate operations.

## Backup recovery and retained keys

Protect encrypted PostgreSQL/EBS backups, uploads, stable `APP_KEY` and approved configuration as related but distinct recovery inputs, with access and retention reviewed by the owner. Never store plaintext keys/env/SQL in Git or public CI artifacts. Snapshot retention and alarms alone do not prove recoverability or define RPO/RTO.

Follow the [offline recovery plan and rehearsal](../infra/aws/recovery.md): select an approved restore point, allocate fresh private database/volume/host targets, keep the source untouched, establish network/role/key bindings, verify integrity and application behavior, then approve any cutover separately. Existing restore planning refuses reused/public/unbound targets and cannot execute AWS restoration. A backup test must actually restore into fresh isolated resources and verify data/uploads/key preservation; production EBS/RDS snapshot/PITR and HTTP-session recovery remain separate operator exercises.

The [backup fixture](../.github/workflows/backup-restore-contract.yml) really rehearses PostgreSQL 16 logical dump/restore and private file/key preservation, including corrupt/reused-target refusal and owned cleanup. It does not establish AWS snapshot/PITR/EBS recovery, application decryption/session round trips, or production recovery objectives.

## Reproducible acceptance without AWS

These native focused checks run without AWS or Docker credentials:

```bash
python3 -m unittest discover -s infra/aws/tests -p test_deploy_release.py -v
python3 -m unittest discover -s infra/aws/tests -p test_rollback_release.py -v
python3 -m unittest discover -s .github/scripts -p test_deployment_source.py -v
python3 -m unittest discover -s .github/scripts -p test_rollback_source.py -v
python3 -m unittest discover -s tests/Docker -p test_release_cycle.py -v
python3 infra/aws/tests/test_restore_plan.py
```

The [disposable application cycle workflow](../.github/workflows/release-cycle.yml) is the canonical actual rehearsal: an owned hosted Linux runner builds two distinct local OCI versions once, performs N → N+1 → N, verifies native HTTPS login and the original session, Teams data/memberships, every asset hash, additive schema/data preservation, exact running image configuration IDs, real queue consumption, singleton scheduler and persistent uploads/private inputs. It then causes database readiness loss after migration, proves all candidate containers stopped and explicitly recovers the last completed release. Only sanitized JSON is uploaded; owned resources are cleaned. Its guards deliberately reject local/shared/self-hosted/remote Docker targets; do not bypass them to run against an application host.

The N+1 fixture uses a committed test-only nullable migration overlay on a copied build context. Evidence records its base Git SHA, overlay hash/destination and derived build target. These are simulated local versions, not attested unmodified production release sources. Array mail proves worker execution without external message delivery. See the #38 PR's actual run/evidence for proof; helper tests alone do not prove the native cycle.

No zero-downtime, availability SLA, real AWS cutover or general schema reversibility is claimed. Single-host Compose restarts can interrupt service. Establish those properties through representative, explicitly authorized tests before advertising them.
