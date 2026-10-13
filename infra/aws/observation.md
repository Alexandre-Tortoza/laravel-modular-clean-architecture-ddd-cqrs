# Optional observation and cost controls

`monitoring = null` creates no alarms, telemetry logs, host telemetry grants or
budget. No script, service or timer is installed or started by this reference.
Terraform tests use mocked providers and plan commands; they cannot confirm live
SNS delivery, billing tags, EC2 telemetry or AWS recovery.

Before selecting observation, the owner supplies the existing regional/account
SNS topic and confirms its CloudWatch publishing policy and subscriptions, all
capacity thresholds, period/evaluation window, retention and contact details.
Eight alarms cover EC2 status/CPU, RDS CPU/free memory/free storage/connections,
application liveness and application readiness. Basic EC2 monitoring requires
periods of at least 300 seconds. Missing data breaches, including a stopped
observer; it is not treated as healthy. `/up` confirms application boot;
`/ready` checks selected dependencies and retained writable storage. These
signals do not establish an availability SLA.

## Status-only logs and private diagnostics

The pre-created `/template/<environment>/runtime` group has explicit bounded
retention. Its `health` stream accepts only the controlled health event, full
configured source revision and two numeric HTTP statuses. The observer never
reads response bodies or forwards diagnostic headers, URL credentials, application
logs, database queries, exceptions or provider diagnostics. The revision is
operator-configured metadata; this probe does not verify running image identity.

RDS PostgreSQL diagnostics remain in the private RDS service with explicitly
selected `rds.log_retention_period` of 1440–10080 whole minutes. Automatic raw
PostgreSQL export is disabled. `log_statement=none` and error-statement controls
reduce logging, but PostgreSQL ERROR/DETAIL can contain application literals.
Those logs are not certified as secret-free and require separately authorized
access, handling and sanitization before any forwarding. Container stdout is
bounded by the host/runtime contracts and is not automatically sent to CloudWatch.

## Prepared host observation

The approved host needs Python 3, the separately approved AWS CLI v2 installation
and its scoped instance profile. Select public metadata in
`observation.config.json.example`; the placeholders intentionally fail validation.
Use the canonical HTTPS origin and the host trust store or a readable approved CA
file. Verification checks the certificate chain and hostname, disables ambient
HTTP proxies and rejects redirects. A network/TLS failure records status zero;
liveness and readiness remain separate. A 503 is a status observation, never an
exception body. Publication failure returns nonzero and omits provider output.

The default command validates metadata offline, without HTTP or AWS calls:

```sh
python3 infra/aws/scripts/observe_health.py --config /path/to/approved-health.json
```

Future execution requires the owner's explicit activation. The `.service.example`
and `.timer.example` are inactive preparation, not installed units. After review,
the operator would install root-owned observer and `runtime_secrets.py` beside it
in `/opt/template-observation`, public metadata in
`/etc/template-observation/health.json`, and the units under their selected names.
The existing `laravel-runtime` identity must read metadata/code/CA; it gets no
Docker group or runtime credential-file access for this task. The example
one-minute timer cadence must be reviewed against the selected alarm window,
request volume and telemetry price. No enable/start command is executed here.
Update the configured revision explicitly after a successful promotion; stale
metadata is not evidence of the deployed artifact.

The host can put metrics only in `Template/<environment>/Runtime` and the selected
region, plus append to the exact existing health log stream. `PutMetricData`
requires resource `*` because AWS provides no resource-level ARN for that action;
namespace and region conditions bound that exception. It cannot create streams
or forward raw logs. The helper suppresses shared/exported AWS credentials and
Boto profiles, using only the EC2 instance profile.

## Cost, backup and recovery limits

Budgets remain off unless the owner chooses a positive monthly amount/currency,
threshold, email subscribers and scope. Account scope includes the account's
other workloads; environment scope uses the `Environment` cost-allocation tag
and requires its billing activation, which is not performed or verified here.
Actual and forecast notifications do not cap spending, stop resources or provide
immediate billing data. Contact addresses are operator-approved state metadata.

Review regional prices and expected usage for eight standard alarms, two custom
metrics, PutMetricData frequency, structured log ingestion/retention, SNS and
budgets, alongside EC2/EBS, RDS storage/backups and retained DLM snapshots. Snapshot
and log retention changes affect cost and recovery windows; no financial amount,
currency, capacity, savings or free-tier entitlement is inferred by tests.

The inherited RDS automated-backup retention/final snapshot/deletion protection
and EBS DLM snapshot retention remain intact. Recovery inputs express owner
targets; they are not measured production RPO/RTO. EC2 is one failure domain;
Single-AZ RDS has no standby and retained uploads on one EC2/EBS stack require
recovery after host/AZ loss. Database and upload snapshots are not inherently
transactionally coordinated. The restore rehearsal must bind compatible source,
uploads, stable recovered APP_KEY and configuration, using fresh target resources
and private access. Follow the separate recovery procedure and record its actual
scope and results. AWS snapshot/PITR and live alert activation remain unexecuted.

References: [RDS PostgreSQL log retention](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_LogAccess.Concepts.PostgreSQL.overview.parameter-groups.html),
[PostgreSQL diagnostic logging](https://www.postgresql.org/docs/16/runtime-config-logging.html),
[RDS point-in-time recovery](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_PIT.html).
