#!/usr/bin/env bash
set -euo pipefail
bundle="${1:?Provide the current OCI release directory}"
previous="${2:?Provide the predecessor OCI release directory}"
revision="${3:?Provide the current source revision}"
previous_revision="${4:?Provide the predecessor source revision}"
previous_source="${5:?Provide the predecessor checkout}"
test "$revision" != "$previous_revision"
python3 .github/scripts/image_release.py verify --directory "$bundle" --previous "$previous"
for role in php nginx; do
    skopeo copy "oci-archive:$bundle/$role.oci.tar" "docker-daemon:laravel-$role:oci-current"
    skopeo copy "oci-archive:$previous/$role.oci.tar" "docker-daemon:laravel-$role:oci-previous"
done
printf 'Verify current OCI artifact %s over HTTPS.\n' "$revision"
bash tests/Docker/https-smoke.sh laravel-php:oci-current laravel-nginx:oci-current "$revision"
printf 'Recover predecessor OCI artifact %s over HTTPS without rebuilding.\n' "$previous_revision"
(
    cd "$previous_source"
    bash tests/Docker/https-smoke.sh laravel-php:oci-previous laravel-nginx:oci-previous "$previous_revision"
)
python3 .github/scripts/image_release.py verify --directory "$bundle" --previous "$previous"
printf 'Current and predecessor OCI archives retained their manifest digests and complete bytes.\n'
