# GitHub AWS identity activation proposal

The prepared identities are disabled by default. `.github/aws-identities.json` contains only empty public metadata and `enabled:false`; no permanent AWS keys, credential secrets or inferred cloud account/region. This issue prepares a reviewable contract, not live IAM/GitHub settings or an AWS login. CI of pull requests executes only offline policy/RSA/mock-plan tests.

## Separate authorities

- Publication reuses the optional `container_registry.publisher` role from the private registry contract. Its exact GitHub provider/audience/subject grants upload only to the selected PHP/Nginx ECR repositories. Read-before-publication idempotence is added by the image-publication issue; this identity does not gain deployment, secret, IAM or Terraform access.
- `github_deployment_identity` is opt-in and creates a separate role/subject. It may send only the selected account-owned SSM document to the selected EC2 instance. The document content/version and its narrow release parameters need their own review before activation; a private name alone does not make arbitrary shell commands safe. AWS's shared `AWS-RunShellScript` is rejected. No image publication, secret read, IAM, Terraform or interactive Session Manager permission is granted.
- `ssm:GetCommandInvocation` needs `Resource:*` because this API has no resource-level authorization. The policy constrains the requested region; it can still observe invocation output from other commands in that region. Approval must consider that access and ensure command output contains no secrets. This is not an instance-level read restriction.
- No Terraform CI role is created: Terraform tests use mocked providers and plan-only runs. Provisioning is a separate owner-approved operation through the existing state/backend bootstrap authority. The project does not silently register or take ownership of an account-wide OIDC provider.

IAM trust uses `StringEquals` for audience `sts.amazonaws.com` and the exact immutable repository/owner/environment subject. No wildcard, PR subject, tag or shared publication/deployment environment is accepted by the deployment identity. GitHub environments replace the branch portion of the OIDC subject; approved branch enforcement therefore also needs the protected environment's branch policy and signed workflow claims. AWS does not support arbitrary GitHub custom claim keys as IAM conditions.

## Concrete owner approval and external setup

After reviewing these files, the owner must separately approve the account/region, existing OIDC provider, two roles, selected ECR namespaces, SSM document/EC2 target and GitHub protection settings. No command here performs those changes. Required GitHub controls:

1. An existing protected `main` default branch, with the reviewed quality/coverage policy.
2. Two pre-existing environments with distinct numeric and GraphQL node IDs, one for publication and one for deployment.
3. At least one concrete required human User/Team reviewer, self approval prevented, administrator bypass disabled.
4. A custom environment deployment policy with exactly one `main` rule of type `branch`; no tags, wildcard or additional/hidden rules.
5. Default immutable OIDC subjects enabled and the actual subject prefix inspected read-only. The preflight rejects custom/unknown/legacy templates rather than guessing a legacy subject. The account's GitHub provider must trust the explicit STS audience and operator-verified provider URL.

Only after that approval/provisioning should the owner fill the tracked manifest and enable it through a reviewed change. Record repository full name/ID/owner ID, AWS account/region, each role ARN and environment name/ID/node ID, the exact effective subject, approved workflow paths, and publisher `ecr_repository_arns` keyed by `php`/`nginx`. Obtain these public identifiers from approved Terraform outputs and GitHub GET metadata; do not store access keys in repository/environment secrets. Do not copy the upstream repository's IDs into a new template instance.

The read-only APIs used are repository metadata, protected main/history comparisons, OIDC customization, environment details and deployment branch policies. `actions:read` and `contents:read` are sufficient; there is no settings-write permission or environment-create call. Missing/unreadable controls fail closed. The existence/ID check runs before the job is scheduled with `environment:name`, so a typo cannot silently create an unprotected environment. The same check is rerun after human approval and before any token request, detecting deleted/recreated environments or changed protection.

## Executable workflow boundary

`aws-identity.yml` is manual/main only for the protected path. A PR gets only offline tests and no `id-token` permission or privileged job. The protected diagnostic, after the approved checks/review, may request a GitHub JWT and validate its real RS256 signature against GitHub's TLS-authenticated JWKS. It validates issuer, exact audience/subject, immutable repo/owner and environment node IDs, protected main/ref/event, workflow path/SHA, source history and token times. Its output contains only selected public claims. It never exchanges the token with STS, pushes an image, invokes SSM, applies Terraform or creates a release.

Callers such as the separately prepared publisher must use `.github/scripts/aws_identity_preflight.py` before scheduling an environment and again after approval, with identical purpose/environment/source/role inputs. The preflight writes approved public outputs to `GITHUB_OUTPUT`. Its `--manifest` defaults to the tracked `.github/aws-identities.json`.

The verifier can optionally receive `EXPECTED_IDENTITY_JSON --token-file "$RUNNER_TEMP/verified-oidc.jwt"`. After signature/claim validation, it exclusively creates a mode-0600 temporary file; it rejects overwrite and locations outside `RUNNER_TEMP`. A separately authorized AWS step can consume that same verified token through `web-identity-token-file`, then remove it. Never upload the token, log it, persist it in the workspace, use permanent credentials, or request a second uninspected token. The identity diagnostic itself keeps the token in memory and does not write this file.

Offline TDD covers missing/changed environments, reviewer/self-review/bypass failures, wildcard/tag/hidden branch policies, source/identity mismatches and purpose separation. Native RSA tests reject tampering, wrong keys/algorithm, issuer/audience/subject/IDs/environment node, PR/event/ref, workflow SHA/path and invalid token times. Terraform minimum/current plan tests prove prepared trust/permission structure without AWS; the future EC2 ID remains computed, and the policy is constructed from that selected resource.

Live approval, issued GitHub claims in the protected job, actual IAM trust/STS and successful publication/deployment remain unexecuted until the owner explicitly activates their respective operations. A green offline test does not certify those effects. The identity PR remains Draft while mandatory external environments/activation are absent.

References: [GitHub AWS OIDC](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-in-aws), [OIDC reference](https://docs.github.com/en/actions/reference/security/oidc), [environment APIs](https://docs.github.com/en/rest/deployments/environments), [branch policies](https://docs.github.com/en/rest/deployments/branch-policies), [GitHub issuer discovery](https://token.actions.githubusercontent.com/.well-known/openid-configuration), [AWS SSM authorization](https://docs.aws.amazon.com/service-authorization/latest/reference/list_ssm.html).
