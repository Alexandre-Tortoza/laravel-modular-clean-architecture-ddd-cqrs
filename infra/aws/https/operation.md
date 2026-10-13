# Direct EC2 HTTPS contract

This is an opt-in Nginx container termination, without an ALB or a host reverse proxy. Build the `nginx-tls` target from the same full source revision as PHP. Approval, immutable image publication/deployment, DNS changes and certificate issuance remain separate operations. The fixture in `tests/Docker/https-smoke.sh` uses ephemeral self-signed certificates and private PostgreSQL; it never requests a real certificate or changes DNS.

## Explicit inputs

Fill `https.env.example` with owner-approved inputs. `TLS_DOMAIN` must be one lowercase DNS hostname (no URL, port, wildcard or IP). `TLS_CERTIFICATE_MECHANISM` is required: `provided`, `acme-webroot`, or `acme-dns`. There is no inferred domain, CA or DNS provider. Point `PRODUCTION_RUNTIME_ENV_FILE` at the restricted runtime file from the approved secret source: `APP_URL=https://<the same TLS_DOMAIN>`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES=`. Direct TLS is reported using Nginx's native `$https` FastCGI parameter; external `X-Forwarded-*` assertions do not establish trust. No proxy is needed here.

The existing Terraform `web_ingress_ipv4_cidrs` controls 443. Set `enable_http_ingress=true` only after approving port 80 for canonical redirects or HTTP-01. The Compose file binds only the explicit host IPv4 address on 80/443; it does not change security groups or open a firewall. PHP-FPM, database, Redis and the internal Nginx health listener have no host ports. No HTTP application listener is published: HTTP only returns 308 to the configured HTTPS host, or a selected ACME token. Unknown TLS SNI and Host values are rejected. The internal loopback health response checks Nginx startup; use HTTPS `/up` for application liveness and `/ready` for selected dependencies.

## Certificate origin and first activation

The operator selects the trusted issuing CA, validates its chain and DNS ownership, records an expiration alert and approves the issuance mechanism. The installer validates DNS SAN/name, at least 24 hours of remaining validity, and the matching unencrypted key; it does not establish CA trust, issue a certificate or store an ACME account. The chain must be leaf first followed by intermediates. No private keys belong in Git, build contexts, images, Terraform state or logs.

- `provided`: an approved external certificate manager delivers the full chain/key; HTTP challenge paths return 404.
- `acme-dns`: an approved external ACME client performs DNS-01 using narrowly scoped credentials kept outside containers and Terraform; HTTP challenge paths return 404. DNS updates require owner approval.
- `acme-webroot`: an approved external ACME client writes HTTP-01 tokens under `TLS_ACME_WEBROOT/.well-known/acme-challenge`. Only base64url token names are served, without PHP or symlinks. The directory is mounted read-only into Nginx and is never the application public root. A directory created by the operator is still required for the other modes; keep it empty.

For first HTTP-01 issuance, the steady-state TLS container cannot start without a valid certificate. The operator must approve and arrange a temporary challenge-only listener (no PHP/application routes), or choose DNS-01/provided for initial issuance. This repository does not silently generate a production self-signed certificate or expose the Laravel application over HTTP to bootstrap ACME.

Install an already-issued pair using the host's Python 3 and OpenSSL. Obtain the non-root image's numeric group ID from the approved digest (`nginx` in the pinned Alpine image is GID 101), then use that explicit ID. Run the installer as the owner of the target directory, usually root; its group and children are readable only by that Nginx group. The target must not be a symlink or writable by group/others. Example with deliberately unresolved values:

```sh
python3 infra/aws/https/install-certificate.py \
  --domain "$TLS_DOMAIN" --mechanism "$TLS_CERTIFICATE_MECHANISM" \
  --fullchain "$ISSUED_FULLCHAIN" --private-key "$ISSUED_PRIVATE_KEY" \
  --directory "$TLS_CERTIFICATE_DIRECTORY" --nginx-gid "$APPROVED_NGINX_GID"
```

The entire certificate directory is mounted read-only, so atomic replacement of `current` remains visible to the container. Mounting individual certificate files would pin old inodes and is not supported. The installer retains previous pairs for explicit recovery; protect the host disk and manage retention deliberately. Use the retained `/srv/laravel/uploads` EBS mount for PHP storage; no application `.env` or certificate enters Nginx public files.

## Renewal and recovery

Configure the approved external manager's renewal schedule, staging test and expiry alert before activation. A renewal hook invokes the same offline installer only after successful issuance and validation. It atomically installs a matching versioned pair and swaps `current`; failed validation preserves the active pair. It neither starts containers nor reloads them automatically. The hook/operator, on the host with approved Docker access, validates the active TLS configuration and then reloads the master:

```sh
docker compose --env-file "$APPROVED_HTTPS_ENV" -p "$APPROVED_PROJECT" \
  -f compose.production.https.yaml exec -T nginx nginx -t -c /tmp/nginx/tls.conf
docker compose --env-file "$APPROVED_HTTPS_ENV" -p "$APPROVED_PROJECT" \
  -f compose.production.https.yaml exec -T nginx nginx -s reload -c /tmp/nginx/tls.conf
```

Invoke reload only after `nginx -t` succeeds. Existing workers keep the previous pair until the graceful reload; verify the served serial/chain, HTTPS health, secure login and the expiration monitor. A failed validation/reload requires the operator to atomically restore the retained previous `current` symlink, repeat `nginx -t`, and reload. Do not restart onto an invalid pair or delete the known-good version. ACME account data and DNS credentials remain in the external manager's restricted storage, with their own approved backup procedure. No container receives the Docker socket.

The disposable CI rotates a real ephemeral pair, validates and reloads Nginx, observes the new served serial and retains the authenticated session. It verifies browser-trusted fixture CA/name, HTTPS redirects/cookies, rejection of forwarded-host/proto spoofing, denied private files/symlinks, and both webroot/provided challenge behavior. This proves the runtime contract, not real DNS reachability, public CA issuance, a live renewal schedule or production activation.

References: [Nginx HTTPS](https://nginx.org/en/docs/http/configuring_https_servers.html), [Nginx SSL module](https://nginx.org/en/docs/http/ngx_http_ssl_module.html), [Certbot issuance/renewal and webroot](https://eff-certbot.readthedocs.io/en/stable/using.html).
