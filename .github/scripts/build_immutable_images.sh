#!/usr/bin/env bash
set -euo pipefail
mkdir -p image-release/current image-evidence
revision="$(git rev-parse HEAD)"
if test "$GITHUB_EVENT_NAME" = pull_request; then
    version=v0.0.1
    previous_revision="$(git rev-parse HEAD^)"
    test "$revision" != "$previous_revision"
    git worktree add --detach "$RUNNER_TEMP/previous-source" "$previous_revision"
    mkdir -p image-release/previous
    (
        cd "$RUNNER_TEMP/previous-source"
        SOURCE_REVISION="$previous_revision" RELEASE_VERSION=v0.0.0 RELEASE_DIRECTORY="$GITHUB_WORKSPACE/image-release/previous" \
            docker buildx bake --allow "fs.write=$GITHUB_WORKSPACE/image-release/previous" --file "$GITHUB_WORKSPACE/docker-bake.release.hcl" --progress=plain release
    )
    python3 .github/scripts/image_release.py create --directory image-release/previous --source "$RUNNER_TEMP/previous-source" \
        --source-revision "$previous_revision" --version v0.0.0
else
    test "$GITHUB_REF" = refs/heads/main
    version="${RELEASE_VERSION:?Provide the stable release version}"
    SOURCE_REVISION="$revision" python3 -c 'import os,sys;sys.path.insert(0,".github/scripts");from image_release import validate_identity;validate_identity(os.environ["SOURCE_REVISION"],os.environ["RELEASE_VERSION"])'
    if test "$INITIAL_RELEASE" = true; then
        test -z "$PREVIOUS_RUN_ID"
    else
        test -n "$PREVIOUS_RUN_ID"
        python3 .github/scripts/image_release_source.py fetch-previous --run-id "$PREVIOUS_RUN_ID" --directory image-release/previous
        previous_revision="$(python3 -c 'import json;print(json.load(open("image-release/previous/image-release.json"))["source_revision"])')"
        git worktree add --detach "$RUNNER_TEMP/previous-source" "$previous_revision"
    fi
fi
SOURCE_REVISION="$revision" RELEASE_VERSION="$version" RELEASE_DIRECTORY="$GITHUB_WORKSPACE/image-release/current" \
    docker buildx bake --file docker-bake.release.hcl --progress=plain release
previous=()
if test -d image-release/previous; then previous=(--previous image-release/previous); fi
python3 .github/scripts/image_release.py create --directory image-release/current --source . --source-revision "$revision" --version "$version" "${previous[@]}"
if test -d image-release/previous; then
    bash tests/Docker/immutable-images-smoke.sh image-release/current image-release/previous "$revision" "$previous_revision" "$RUNNER_TEMP/previous-source"
else
    for role in php nginx; do
        skopeo copy "oci-archive:image-release/current/$role.oci.tar" "docker-daemon:laravel-$role:oci-current"
    done
    bash tests/Docker/https-smoke.sh laravel-php:oci-current laravel-nginx:oci-current "$revision"
    python3 .github/scripts/image_release.py verify --directory image-release/current
fi
cp image-release/current/image-release.json image-evidence/current.json
if test -f image-release/previous/image-release.json; then cp image-release/previous/image-release.json image-evidence/previous.json; fi
python3 - <<'PY'
import json
from pathlib import Path
for path in sorted(Path('image-evidence').glob('*.json')):
    manifest = json.loads(path.read_text())
    print(json.dumps({'release': path.name, 'source_revision': manifest['source_revision'], 'version': manifest['version'],
                      'images': {role: {key: image[key] for key in ('digest', 'config_digest', 'archive_sha256')} for role, image in manifest['images'].items()}}, sort_keys=True))
PY
