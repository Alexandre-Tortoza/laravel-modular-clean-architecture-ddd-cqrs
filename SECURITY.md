# Security reporting

Do not disclose exploitable vulnerabilities, credentials, personal data, database dumps, private logs or private reproduction details in public issues, PRs or discussions. General security improvements without exploitable/private details can follow the ordinary contribution process.

## Private reporting channel

GitHub private vulnerability reporting was checked for this repository on 2026-10-13 and is **disabled**. No security email address or alternative private channel has been confirmed. Do not assume an active reporting form or invent a contact address.

The proposed channel is GitHub **Security → Advisories → Report a vulnerability**, after the repository owner explicitly enables private vulnerability reporting. This document does not enable it or change repository settings. Until an actual private channel is confirmed, retain sensitive findings privately and ask the owner to establish a private channel using only a non-sensitive request through an already available contact. Do not attach the finding to that request. Publication readiness includes confirming the working channel and responsible recipients.

## Responsible disclosure procedure

Once a private channel is available, provide the affected template/application revision, relevant component and configuration, impact, minimal reproduction using synthetic data, prerequisites and any suggested mitigation. Redact tokens, passwords, keys, personal identifiers and connection details. Do not test other people's systems, access data without permission or execute destructive reproductions.

Coordinate reproduction, fix verification and public disclosure timing privately with the maintainer. There is no promised response time, bug bounty or guaranteed support period. Do not publish technical exploit details until the owner has assessed affected users and agreed on responsible disclosure. If a secret was exposed, its owner must rotate/revoke it and investigate impact; deleting a Git commit or closing a PR does not revoke a credential.

## Scope and operational responsibilities

The template is under development and no supported published release series is declared here. Reports must identify an actual affected revision; a green test suite is not a security certification. Derived applications own their dependencies, deployment configuration, business authorization and vulnerability response. Template updates are not propagated to them automatically.

Production operators must protect stable `APP_KEY` and backups, disable debug, validate HTTPS/secure cookies and proxy boundaries, restrict database/network access, and operate queues and retained storage deliberately. Demo credentials are for explicit disposable local/testing seeders only. Prepared AWS/OIDC/deployment workflows remain opt-in; no live activation or production security review is claimed by their fixtures.

The [quality workflow](.github/workflows/tests.yml) runs locked dependency audits and regression gates. Vulnerable/abandoned packages fail its policy; a documented, owner-reviewed remediation is required rather than disabling the checks. Keep private investigation artifacts out of Git, CI logs and public uploads. See [CONTRIBUTING.md](CONTRIBUTING.md) for ordinary review and external-operation limits.
