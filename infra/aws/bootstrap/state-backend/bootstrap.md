# Separate state-backend bootstrap

This is a standalone Terraform root, **not** a module called by `infra/aws`. Merely initializing or validating the application root does not create the bucket. Its AWS provider is pinned by the committed `.terraform.lock.hcl`; the selected AWS 6.68 release uses separate bucket configuration resources. Terraform 1.10 or later (below 2.0) is required for [native S3 state locking](https://developer.hashicorp.com/terraform/language/backend/s3).

The proposed bucket enables versioning, SSE-S3 (`AES256`), all four public-access blocks and BucketOwnerEnforced ownership (ACLs disabled). Its policy denies non-TLS requests and principals outside the explicit IAM role list. It has no automatic version-expiration lifecycle. `force_destroy = false` avoids deleting objects on bucket removal, and `prevent_destroy = true` blocks Terraform deletion/replacement while this resource declaration remains. These guards do not prevent administrators from editing the configuration or calling AWS directly. See [S3 public-access blocking](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-control-block-public-access.html), [encryption](https://docs.aws.amazon.com/AmazonS3/latest/userguide/UsingServerSideEncryption.html) and [Terraform prevent_destroy limits](https://developer.hashicorp.com/terraform/language/meta-arguments/lifecycle#prevent_destroy).

## Inputs and identities

All inputs in `variables.tf` are explicit. The example is a placeholder, not an automatic variable file. Choose the region, partition, globally unique bucket name, environment and concrete role ARNs. Include the bootstrap/recovery role in the approved list to avoid locking out the operator. This does not create roles or grant IAM access: the policy is a restrictive guard, and each role also needs a separately reviewed identity policy. No access keys, session tokens or application secrets belong in Terraform variables, backend files or plans.

`state_writer_policy` is a reviewable template for **one** environment. It grants listing of the selected state/lock paths, Get/Put on that state object, and Get/Put/Delete only on its `.tflock`. It grants no deletion of state objects, bucket administration or access to other environment prefixes. Attach it to the appropriate role only through an approved IAM change. Keep the broader bootstrap/recovery role separate from routine state writers. The EC2 application role must not receive state access.

The root uses the default workspace with explicit `<environment>/terraform.tfstate` keys. Use a distinct key and separately scoped role for every environment; arbitrary Terraform workspaces need additional permissions and are not part of this contract. Root credentials and provider credentials are independent concerns; backend region can differ from application region and must be chosen explicitly.

## Offline checks

These commands are safe to execute without AWS credentials:

```sh
terraform -chdir=infra/aws/bootstrap/state-backend fmt -check -recursive
terraform -chdir=infra/aws/bootstrap/state-backend init -backend=false -input=false -lockfile=readonly
terraform -chdir=infra/aws/bootstrap/state-backend validate
terraform -chdir=infra/aws/bootstrap/state-backend test
```

Every test run uses `command = plan` with `mock_provider "aws"`; no resource is created and no AWS API is called. Tests check encryption/versioning/private-access configuration, transport/actor restrictions, environment isolation and scoped state/lock permissions. They do not prove live IAM behavior, name availability, AWS connectivity, lock contention or recovery. The provider download during init uses the public Terraform registry, not AWS.

## Operator sequence — requires additional authorization

No bootstrap, remote init, migration, apply or destroy is performed by this repository's validation workflow. Before proceeding, review the actual region, identities, cost of stored versions and recovery ownership. Use temporary approved role credentials outside versioned files. Review a bootstrap plan from this standalone root under a protected local state; its creation is a separate approved operation, not part of application setup.

After the approved bucket creation, verify encryption, versioning, policy and IAM using authorized AWS checks. AWS recommends waiting 15 minutes after first enabling versioning before writing critical objects. Preserve and back up the bootstrap root's own state separately; it must not depend on a bucket it is creating. See [provider versioning guidance](https://github.com/hashicorp/terraform-provider-aws/blob/v6.68.0/website/docs/r/s3_bucket_versioning.html.markdown).

Only then fill a **local**, ignored `.backend.hcl` file from `infra/aws/environments/backend.hcl.example`. The separately reviewable remote initialization command is:

```sh
# Requires explicit authorization: accesses AWS and initializes remote state.
terraform -chdir=infra/aws init -backend-config=environments/<ENVIRONMENT>.backend.hcl
```

If application state already exists, review and back it up first; migration requires a separate explicitly approved `-migrate-state` operation. Do not use forced copy/reconfiguration to hide a state mismatch. Backend settings are cached in `.terraform` and may enter saved plans: keep both private and unversioned, and never include credentials there.

Record an approved recovery procedure: select and inspect an S3 object version in isolation, confirm environment/lineage/serial and prevent concurrent writers before any restore/cutover. Versioning is recoverability support, not evidence that restore succeeded. Never remove a `.tflock` or force-unlock without verifying that no active writer exists. Neither a real restore nor a lock-contention test has been executed in this issue.
