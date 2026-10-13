#!/usr/bin/env bash
set -euo pipefail
test "$#" -eq 0
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test "${RUNNER_OS:-}" = Linux
test -z "${DOCKER_HOST:-}"
test -z "${DOCKER_CONTEXT:-}"
test "$(docker context show)" = default
test ! -e image-release

source_root="$(mktemp -d "$RUNNER_TEMP/release-cycle-build.XXXXXXXX")"
trap 'rm -rf "$source_root"' EXIT
mkdir -p "$source_root/previous" "$source_root/current" image-release/previous image-release/current
revision="$(git rev-parse HEAD)"
previous_revision="$(git rev-parse HEAD^)"
test "$revision" != "$previous_revision"
git archive "$previous_revision" | tar -x -C "$source_root/previous"
git archive "$revision" | tar -x -C "$source_root/current"

# Only the disposable N+1 build receives this committed additive migration.
# The recorded Git SHA is the base context plus this test-only overlay, not
# an attestation of an unmodified production source tree. Original files stay intact.
cp tests/Docker/migration-fixtures/2030_01_01_000001_expand_release_probe.php "$source_root/current/docker/php/cycle-expand.php"
cat >>"$source_root/current/Dockerfile" <<'EOF'

FROM runtime AS cycle-runtime
COPY --chmod=0644 docker/php/cycle-expand.php /app/database/migrations/2030_01_01_000001_expand_release_probe.php
EOF
python3 - "$source_root/current/docker-bake.release.hcl" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
source = path.read_text()
if source.count('target = "runtime"') != 1:
    raise ValueError('Expected the native PHP release target exactly once')
path.write_text(source.replace('target = "runtime"', 'target = "cycle-runtime"'))
PY

workspace="$(pwd)"
for name in previous current; do
    if test "$name" = previous; then selected_revision="$previous_revision"; version=v0.0.1; else selected_revision="$revision"; version=v0.0.2; fi
    (
        cd "$source_root/$name"
        SOURCE_REVISION="$selected_revision" RELEASE_VERSION="$version" RELEASE_DIRECTORY="$workspace/image-release/$name" \
            docker buildx bake --allow "fs.write=$workspace/image-release/$name" --file docker-bake.release.hcl --progress=plain release
    )
    previous=()
    if test "$name" = current; then previous=(--previous image-release/previous); fi
    python3 .github/scripts/image_release.py create --directory "image-release/$name" --source "$source_root/$name" \
        --source-revision "$selected_revision" --version "$version" "${previous[@]}"
    for role in php nginx; do
        skopeo copy "oci-archive:image-release/$name/$role.oci.tar" "docker-daemon:laravel-$role:oci-$name"
    done
done
python3 .github/scripts/image_release.py verify --directory image-release/current --previous image-release/previous
printf '%s\n' 'Two local simulated releases have verified distinct OCI bytes; N+1 contains the additive test-only migration.'
