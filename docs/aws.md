# AWS reference: EC2 and Docker Compose

AWS is optional. Local application development, Sail, database-backed cache,
sessions and queues do not require an AWS account or credentials. Dependency/image
downloads need their respective registries initially; cached local tests need no
AWS access. This guide describes the checked-in reference, not a provisioned
installation. No AWS provisioning, real certificate issuance, image publication,
SSM deployment or production restore has been performed by the roadmap checks.

The application Terraform root is [infra/aws](../infra/aws). Network resources
are defined there; EC2, RDS and the additional services below require explicit
opt-in inputs. Validation never provisions them. **Do not run `terraform apply`,
`terraform destroy`, an AWS-connected plan, remote backend initialization/state
migration, image publication or deployment without separate owner authorization
for the concrete operation and environment.**

## Topology and failure domains

The approved initial layout is one public EC2 host running Compose, encrypted
persistent uploads on EBS, and private PostgreSQL 16 RDS in Single-AZ mode.
Nginx terminates HTTPS directly on EC2; PHP-FPM, worker, scheduler and the one-off
migration process remain internal. There is no initial ALB, NAT Gateway, ECS,
Fargate or Kubernetes. The [topology contract](../infra/aws/topology.md) contains
the diagram and detailed network restrictions.

| Boundary         | Selected behavior                                                                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Public subnet    | One explicitly selected EC2 AZ; public IPv4 and internet-gateway egress for packages, registry and AWS endpoints.                                                  |
| Web ingress      | 443 from explicit client IPv4 CIDRs; 80 is off unless approved for HTTPS redirects/certificate challenges. No public SSH, FPM, database, cache or management port. |
| Administration   | SSM connectivity through the instance profile; no mandatory public SSH. Protected release workflows use a narrowly scoped custom SSM document.                     |
| Private database | RDS has no public address; TCP 5432 accepts only the EC2 security group. Native PostgreSQL TLS uses `verify-full` and a trusted RDS CA.                            |
| Optional cache   | Valkey is private and off by default; enabling it admits TLS 6379 only from the EC2 security group.                                                                |

This reference does **not** define a private EC2 deployment variant. Moving EC2
into a private subnet requires a separately reviewed access/egress design for
SSM, ECR, Secrets Manager and updates; endpoints, NAT and their costs are not
created automatically. A recovery plan can describe a new private recovery host,
but does not implement or authorize that alternative network.

One host is a failure domain for web, workers and schedules. EBS uploads are
bound to that host's selected AZ. Container restart policies do not survive host
or AZ loss. Single-AZ RDS has no standby failover; the DB subnet group covers at
least two AZs, which is a subnet requirement rather than Multi-AZ availability.
Maintenance and promotion can interrupt service; no zero-downtime or availability
SLA is claimed. See [RDS subnet groups](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_VPC.WorkingWithRDSInstanceinaVPC.html)
and [Multi-AZ standby behavior](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Concepts.MultiAZSingleStandby.html).

## Prerequisites and explicit decisions

For contract checks, use Python 3 and a verified Terraform **1.10.5 or 1.16.5**,
the versions in [AWS contract CI](../.github/workflows/aws-contract.yml).
Both roots require Terraform `>= 1.10, < 2.0` and commit provider lockfiles for
AWS 6.68. Do not update dependencies or bypass the lockfile to validate a guide.
No AWS CLI, account, secret or populated production tfvars is needed for mocked
tests. Native Docker rehearsals additionally need Docker/Buildx/Compose and their
pinned images on a disposable machine; never redirect fixtures to a shared host.

An actual installation additionally needs an owner-approved AWS account/region,
state authorities, existing KMS keys and OIDC provider, supported regional service
choices, owned DNS and trusted certificates, protected GitHub environments, and
reviewed capacity/recovery/cost decisions. The host contract selects Ubuntu 24.04,
an immutable regional AMI/owner and architecture, and explicit versions of all
five Docker APT packages (engine/CLI share a version). Compose must be at least
2.30.0 for [raw service env files](https://docs.docker.com/reference/compose-file/services/#format).
Host runtime-secret/observation execution needs a separately reviewed AWS CLI v2
installation; bootstrap does not download that CLI.

The [base example](../infra/aws/terraform.tfvars.json.example) and
[RDS example](../infra/aws/rds.tfvars.json.example) contain unresolved placeholders.
They are reference schemas, not automatic tfvars or runnable production defaults.
Select inputs from their committed variable definitions:

| Input group                                         | Owner must supply or review                                                                                                                                                                                                                         |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Base](../infra/aws/variables.tf)                   | Region, environment, owned hostname, VPC/subnet CIDRs, named public AZ and private AZs, client CIDRs, EC2/RDS capacities, backup retention, RPO/RTO/rehearsal interval, cost owner and optional amount/currency.                                    |
| [EC2](../infra/aws/compute-variables.tf)            | `ec2_host`: regional AMI/owner/architecture, upload size, pinned Docker versions, initialization permission or retained snapshot, optional snapshot cadence/retention. Initialization defaults false; restored volumes cannot authorize formatting. |
| [RDS](../infra/aws/rds-variables.tf)                | `rds`: full PostgreSQL 16 version, class/storage from base inputs, private AZ, DB identifier/name, separate admin/application usernames, storage/admin-secret KMS keys, CA, UTC windows, deletion protection, final snapshot and storage ceiling.   |
| [Runtime secret](../infra/aws/runtime-secrets.tf)   | `runtime_secrets`: existing application secret and KMS ARNs, matching non-admin DB username and rotation owner; identifiers only.                                                                                                                   |
| [Registry](../infra/aws/registry-variables.tf)      | `container_registry`: distinct PHP/Nginx repository names, public-IPv4/IGW egress, reviewed storage estimate/unit price/currency, optional publisher trust and retained current/previous digests.                                                   |
| [Deployment](../infra/aws/controlled-deployment.tf) | `controlled_deployment` and [deployment identity](../infra/aws/github-identity.tf): exact custom document, selected host/role, Compose project, domain, certificate mechanism, bind address and trusted runtime/TLS/CA paths.                       |
| [Observation](../infra/aws/monitoring-variables.tf) | `monitoring`: approved existing SNS destination/subscriptions, thresholds, periods, log retention; optional budget scope/contacts.                                                                                                                  |
| [Valkey](../infra/aws/valkey.tf)                    | `valkey`: justified version/family/node/AZ, pre-existing RBAC group/user and authentication owner, maintenance/snapshot choices.                                                                                                                    |

`ec2_host`, `rds`, `runtime_secrets`, `container_registry`,
`github_deployment_identity`, `controlled_deployment`, `monitoring` and `valkey`
default to `null`. `enable_http_ingress` defaults false. These defaults do not
remove the root's network definitions or its required base inputs. There is no
inferred region, domain, instance size, budget, contact or credential. Offline
mocks cannot confirm AMI ownership, regional catalog/orderability, DNS ownership,
CA validity, affordability or live IAM; actual verification needs its own approval.

## Separate, versioned state bootstrap

[bootstrap/state-backend](../infra/aws/bootstrap/state-backend/bootstrap.md) is
an independent Terraform root, not an application module. It proposes an S3
bucket with versioning, SSE-S3 encryption, public-access blocks, disabled ACLs,
TLS-only access and an explicit role allowlist. No lifecycle expires state
versions automatically. `force_destroy=false` and `prevent_destroy=true` protect
the declaration; they do not prevent administrator changes outside that contract.

Choose the globally unique bucket name, partition/region, environment and exact
bootstrap/recovery/writer role ARNs. Include the recovery authority before
restricting access. The restrictive bucket policy does not grant identity
permissions. The scoped writer policy allows listing the selected paths,
Get/Put for `<environment>/terraform.tfstate`, and Get/Put/Delete for its
`.tflock`, without deleting state or administering the bucket. The EC2 application
role receives no state access. Use the default workspace and distinct explicit
keys/roles per environment.

The [backend configuration](../infra/aws/backend.tf) enables native S3
`use_lockfile=true`; the [local metadata example](../infra/aws/environments/backend.hcl.example)
supplies bucket/key/region. No DynamoDB lock table is defined. Backend region is a
separate owner choice. Remote initialization and state migration follow only
separately approved bootstrap/verification. Keep the bootstrap's own state
independent of the bucket it creates. Local backend settings, `.terraform`, saved
plans and state stay private/unversioned; no credentials belong in them. Recover
an inspected S3 version only after checking lineage/serial and excluding active
writers. Never force-unlock an unverified live writer. Versioning is recovery
support, not a successful restore rehearsal. See [S3 backend locking and permissions](https://developer.hashicorp.com/terraform/language/backend/s3).

## Offline checks, without backend or AWS access

From the repository root, with either verified Terraform version available as
`terraform`, run this Bash block; repeat with the other matrix version when
checking compatibility. Initial provider downloads use the public Terraform
registry. A verified local provider cache can serve a disconnected run.

```bash
set -euo pipefail
python3 .github/scripts/test_infrastructure_policy.py
for directory in infra/aws infra/aws/bootstrap/state-backend; do
    python3 .github/scripts/infrastructure_policy.py sources --directory "$directory"
    terraform -chdir="$directory" fmt -check -recursive
    terraform -chdir="$directory" init -backend=false -input=false -lockfile=readonly
    terraform -chdir="$directory" validate
    terraform -chdir="$directory" test -verbose -json \
        | python3 .github/scripts/infrastructure_policy.py plan
done
python3 -m unittest discover -s infra/aws/tests -p 'test_*.py'
```

Every committed Terraform test explicitly uses `command = plan` and mocked AWS
providers. Preserve that boundary: Terraform tests otherwise default to apply.
The streamed plan guard checks each mock plan and requires a successful final
summary; do not replace it with a pipeline that hides Terraform failure or save
verbose plans as public artifacts. Tests cover source/IAM restrictions and
positive/negative contracts, not real AWS connectivity or permissions. The
operational helper tests use synthetic metadata and doubles; no execution flag,
AWS credentials, production tfvars or remote backend is needed.

## Runtime IAM, secrets and persistent data

The instance profile uses IMDSv2. It pulls only the approved private ECR
repositories and, when selected, reads exactly the application secret and
KMS key. Secret decryption is bounded by Secrets Manager service/context; an
explicit deny excludes all other secrets, including the RDS administrator's.
Application containers do not receive static AWS credentials or a metadata-fetch
helper. Routine state writers, the image publisher and the SSM deployment role
are separate authorities. `GetCommandInvocation` has no resource-level ARN
support: its region-scoped read can observe other commands in that region, a
limitation requiring owner review and secret-free command output. See the
[identity proposal](../infra/aws/identity-activation.md) and
[AWS SSM authorization reference](https://docs.aws.amazon.com/service-authorization/latest/reference/list_ssm.html).

RDS manages a separate administrator secret. The application uses the restricted
role prepared by [the SQL template](../infra/aws/templates/rds-application-role.sql);
Terraform never runs SQL. Approved role/password bootstrap is a separate private
operator operation. TLS requires the real endpoint hostname, `DB_SSLMODE=verify-full`
and a reviewed read-only RDS CA bundle, including in cached Laravel configuration.

Application secret values remain outside Terraform, user-data, images, Git,
commands and artifacts. The existing Secrets Manager JSON contains stable
`APP_KEY`, `DB_USERNAME`, `DB_PASSWORD` and optional `REDIS_PASSWORD`.
[Runtime metadata](../infra/aws/runtime-secrets.config.json.example) contains
only public connection/secret identifiers. The [delivery helper](../infra/aws/scripts/runtime_secrets.py)
validates metadata offline by default; a separately approved host execution uses
only the instance profile and atomically writes an owned 0600 file in a 0700
directory. It suppresses shared AWS/Boto profiles and rejects unsafe files or
APP_KEY replacement. A failed fetch preserves the prior file.

The [release Compose contract](../compose.production.release.yaml) gives PHP,
worker, scheduler and migration the **same** raw runtime env file, retained EBS
upload bind and read-only RDS CA. Do not shell-source the credential file, pass it
as Compose interpolation `--env-file`, print resolved production Compose/envs,
or rebuild an image with its values. Runtime caching happens privately on
container startup. Database credential rotation requires approved drain, matching
DB/password/secret stages and explicit service recreation, with 90-second worker
grace and one scheduler. Failed preparation cannot undo a changed DB password.
APP_KEY and its recovery remain stable and separate from administrator rotation.
Detailed encoding and rotation boundaries are in the [runtime secret contract](../infra/aws/topology.md#application-runtime-secrets).

## Direct HTTPS and certificate lifecycle

Use the `nginx-tls` image target with the same source/assets as PHP. Select the
owned domain and `provided`, `acme-webroot` or `acme-dns` mechanism explicitly.
`APP_URL` is that HTTPS origin, secure session cookies are enabled, and trusted
proxies are empty for direct termination. HTTP only redirects to the canonical
HTTPS host or serves approved challenge tokens; it never exposes the application.
Nginx alone publishes the selected host 80/443 bindings. Opening the security
group's port 80 remains a separate opt-in.

The [HTTPS procedure](../infra/aws/https/operation.md) explains certificate origin,
owner-verified CA trust/DNS, private key permissions, atomic pair installation,
expiry alerts, renewal and validated graceful reload. It neither requests a
certificate nor alters DNS. First HTTP-01 issuance needs a separately approved
challenge-only listener or another initial mechanism; no production self-signed
fallback or HTTP application bypass is created. Keep account/DNS credentials
and private keys outside images/state/receipts. Mount the entire certificate
pair directory read-only so atomic replacement is visible.

## Protected build, publication, deployment and rollback

All live paths are inactive by default. [Identity metadata](../.github/aws-identities.json)
and [deployment metadata](../.github/aws-deployment.json) have `enabled:false`
and intentionally empty identifiers. Do not copy upstream IDs into a derived
project or substitute `$LATEST`, `$DEFAULT`, tags or guessed hashes.

The owner-approved sequence is:

1. Review/provision the selected state/network/host/database/registry/secret
   authorities separately; prepare retained uploads, runtime env and trusted
   database/TLS material without formatting a restored volume.
2. Establish protected `main` and **two existing** publication/deployment
   environments with concrete reviewers, no self approval/admin bypass, and
   exactly the `main` branch rule. Review actual immutable repository/owner and
   numeric/node environment IDs, OIDC audience/subject and distinct role ARNs.
3. Build PHP and HTTPS Nginx once from the same full source SHA/assets using
   [immutable-images.yml](../.github/workflows/immutable-images.yml). Verify OCI
   manifest digests, lock/assets/provenance and predecessor package. Ordinary
   PR/default builds upload sanitized manifest/hash evidence only. Full OCI
   retention additionally needs explicit opt-in and protected human approval;
   the build does not assume AWS credentials.
4. Approve [publication](../.github/workflows/publish-images.yml) with
   `publish:true` and `ENABLE_IMAGE_PUBLISHING=true`. It validates the exact
   completed producer/source/SemVer and N-1 (or explicit initial release), then
   copies verified OCI bytes without rebuilding or changing digests. Conflicting
   immutable ECR tags fail; an existing identical digest is idempotent. Retain
   both repositories' completed N/N-1 images; no automatic expiry policy exists.
5. Export the actual reviewed SSM document's ARN, numeric version, AWS-produced
   SHA-256, selected instance and complete public contract/fingerprint after
   separately authorized reconciliation. Enable deployment only by reviewed
   activation. [deploy.yml](../.github/workflows/deploy.yml) additionally requires
   manual protected-main dispatch, `deploy:true` and `ENABLE_EC2_DEPLOY=true`.
6. Use [rollback.yml](../.github/workflows/rollback.yml) only after explicitly
   approving retained schema compatibility and the exact completed predecessor
   or recovery of a failed pending candidate. `rollback:true` and
   `ENABLE_EC2_ROLLBACK=true` remain separate opt-ins.

The read-only protection preflight runs **before** scheduling an environment job,
then checks the same immutable identities again after human approval. The signed
JWT is validated and that same token is used for the dedicated short-lived role;
no permanent keys or PR/push credentials are supplied. See [OIDC activation](../infra/aws/identity-activation.md),
[deployment activation](../infra/aws/deployment-activation.md) and
[rollback procedure](../infra/aws/rollback.md) for the exact contracts.

The host and GitHub serialize operations per environment. Deployment verifies
source/digests/assets/EBS and completed predecessor, migrates once before draining
old processes, starts web, requires verified HTTPS readiness, starts one worker
and scheduler, and rechecks readiness/processes before committing receipts.
Private receipts retain public release/configuration identity and timestamps;
failed candidates retain pending phases and never become successful predecessors.
A completed retry rechecks health without rerunning migrations. Rollback restores
retained code/configuration and checks readiness/assets/processes, with no reverse
DDL, data/secret replacement, rebuild or `down -v`. Expand/contract compatibility
is an owner decision, not a proof that every migration is reversible. An unknown
SSM acknowledgement/timeout requires private inspection of the known command;
never blindly resend it or erase pending state.

## Observation, backups and recovery

[Observation](../infra/aws/observation.md) is opt-in: no observer timer is installed
or enabled by default. Selected alarms cover host/database capacity and separate
application liveness/readiness. SNS destinations, subscriptions, thresholds,
periods and retention are operator inputs. The only CloudWatch runtime log is a
controlled status event with the **configured** source revision; it does not
verify the running image. Raw application/SQL/provider output is not forwarded.
Native private RDS diagnostics have explicit retention; ERROR/DETAIL can contain
literals despite statement controls, so access/sanitization require their own
approval. Budget alerts notify; they do not cap spending or stop resources.

Choose RDS automated retention, final snapshot/deletion safeguards and optional
EBS DLM cadence/retention, then plan compatible DB/upload recovery points.
Independent snapshots do not establish application consistency, RPO or RTO.
Retain the original APP_KEY/secret authority, KMS access and certificate recovery
separately. See the [new-target recovery procedure](../infra/aws/recovery.md):
restore a new private DB and encrypted upload volume/host, leave source resources
untouched and formatting disabled, verify role/TLS/schema/data/uploads/decryption,
then perform auth/session/worker/readiness checks and measure achieved recovery.
Traffic cutover and cleanup require separate approval.

Evidence has distinct limits. The [backup rehearsal](../.github/workflows/backup-restore-contract.yml)
uses real logical PostgreSQL dump/restore and filesystem/key fixtures, not AWS
snapshot/PITR/EBS restoration. The [application release cycle](../.github/workflows/release-cycle.yml)
uses two local simulated immutable versions with a compatible additive migration;
it exercises N → N+1 → N, HTTPS login/original session, Teams data/uploads/assets,
actual queue consumption, singleton scheduling and readiness-failure recovery.
These disposable tests do not prove real AWS/SSM, production cutover, general
schema reversibility, zero downtime or production-sized RPO/RTO.

## Optional Valkey and recurring cost review

`valkey=null` creates no ElastiCache resources. Database cache/session/queue
remain defaults, and enabling managed Valkey does not switch application drivers.
The opt-in contract has one private TLS/encrypted primary with pre-existing RBAC,
no replicas/failover/Multi-AZ/sharding/Horizon. Authentication ownership, actual
user-group membership/ACLs and regional compatibility are **unknown offline**;
review them before activation. Runtime metadata selects cache/session/queue
independently and obtains `REDIS_PASSWORD` through the existing application secret.
One primary is not a durable queue/session availability guarantee. See the
[optional Valkey contract](../infra/aws/topology.md#optional-managed-valkey).

Review current regional rates against explicit workload/retention assumptions;
record currency, cost owner and any approved ceiling rather than copying a test
fixture or free-tier assumption:

| Cost area       | Usage to account for                                                                                                                                                  |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| EC2/network     | Instance hours, public IPv4, transfer and possible cross-AZ traffic; no mandatory NAT does not mean free networking.                                                  |
| EBS             | Root/upload capacity and retained snapshots; restore rehearsals add temporary target storage/hosts.                                                                   |
| RDS             | Instance hours, gp3 storage/I/O as applicable, backups/retention and isolated restored targets.                                                                       |
| ECR             | Retained PHP/Nginx N/N-1 image bytes and other retained versions; no lifecycle automatically limits accumulation.                                                     |
| Secrets/KMS     | Stored application/admin secrets, reads/rotation operations and selected key usage.                                                                                   |
| Observation     | Selected alarms/custom metrics, publication frequency, log ingestion/retention, SNS and optional budget services.                                                     |
| Optional Valkey | One selected primary's hours, retained snapshots and transfer plus existing secret/KMS usage; off means no additional ElastiCache allocation, not zero topology cost. |

No price, savings, recommended size or spending cap is inferred by this guide.
An unset `cost_review.monthly_budget` means undecided. Revisit capacity, retention
and the single-host/Single-AZ trade-off before enabling resources or changing
availability requirements. Use [EC2](https://aws.amazon.com/ec2/pricing/),
[RDS](https://aws.amazon.com/rds/pricing/), [EBS](https://aws.amazon.com/ebs/pricing/),
[ECR](https://aws.amazon.com/ecr/pricing/), [VPC](https://aws.amazon.com/vpc/pricing/),
[CloudWatch](https://aws.amazon.com/cloudwatch/pricing/),
[Secrets Manager](https://aws.amazon.com/secrets-manager/pricing/) and
[ElastiCache](https://aws.amazon.com/elasticache/pricing/) pricing for the selected
region and approved usage; this document provides no live quote.
