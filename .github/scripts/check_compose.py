"""Resolve Compose with disposable fixture inputs; never start a container."""

import json
import os
import pathlib
import subprocess
import tempfile

from infrastructure_policy import validate_compose


def main():
    root = pathlib.Path(__file__).resolve().parents[2]
    with tempfile.TemporaryDirectory(prefix="compose-policy-") as directory:
        temporary = pathlib.Path(directory)
        runtime = temporary / "runtime.env"
        runtime.write_text("APP_ENV=testing\n")
        empty = temporary / "compose.env"
        empty.touch()
        environment = dict(os.environ, PHP_IMAGE="fixture.invalid/php:offline",
                           RUNTIME_ENV_FILE=str(runtime), SOURCE_REVISION="0" * 40,
                           SMOKE_PHP_IMAGE="fixture.invalid/php:offline",
                           SMOKE_NGINX_IMAGE="fixture.invalid/nginx:offline",
                           SMOKE_RUNTIME_ENV_FILE=str(runtime), SMOKE_DB_DATABASE="smoke_offline",
                           SMOKE_DB_PASSWORD="offline-disposable-fixture", DB_PASSWORD="offline-disposable-fixture")
        combinations = [
            (["compose.yaml"], [], True, False),
            (["compose.yaml"], ["redis"], True, True),
            (["compose.production.smoke.yaml"], [], False, False),
            (["compose.production.smoke.yaml"], ["redis"], False, True),
            (["compose.production.processes.yaml"], [], False, False),
            (["compose.production.migrations.yaml"], ["release"], False, False),
            (["compose.production.smoke.yaml", "compose.production.processes.yaml", "compose.production.migrations.yaml"], ["release"], False, False),
            (["compose.production.smoke.yaml", "compose.production.processes.yaml", "compose.production.migrations.yaml"], ["release", "redis"], False, True),
        ]
        for files, profiles, development, redis in combinations:
            command = ["docker", "compose", "--env-file", str(empty), "--project-name", "offline-policy"]
            for filename in files:
                command += ["-f", str(root / filename)]
            for profile in profiles:
                command += ["--profile", profile]
            command += ["config", "--format", "json"]
            # Capture resolved environments instead of printing even fixture secrets.
            result = subprocess.run(command, cwd=root, env=environment, capture_output=True, text=True, check=False)
            if result.returncode:
                raise SystemExit("Compose configuration failed: " + ", ".join(files))
            errors = validate_compose(json.loads(result.stdout), development, redis)
            if errors:
                raise SystemExit("; ".join(errors))
            print("Compose config passed: " + ", ".join(files) + " profiles=" + (",".join(profiles) or "off"))


if __name__ == "__main__":
    main()
