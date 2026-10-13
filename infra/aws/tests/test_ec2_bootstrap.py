"""Exercise the rendered storage helper without devices, AWS or privileged commands."""

import base64
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest


class Ec2BootstrapTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        terraform = os.environ.get("TERRAFORM_BINARY", shutil.which("terraform"))
        if not terraform:
            raise RuntimeError("Terraform must be available to test the actual template renderer")
        template = Path(__file__).resolve().parents[1] / "templates/ec2-bootstrap.sh.tftpl"
        cls.rendered = {}
        for initialize in (False, True):
            parameters = {
                "architecture": "amd64",
                "docker_versions": {
                    "engine": "5:29.8.2-1~ubuntu.24.04~noble",
                    "containerd": "2.2.4-1~ubuntu.24.04~noble",
                    "buildx": "0.32.1-1~ubuntu.24.04~noble",
                    "compose": "5.1.0-1~ubuntu.24.04~noble",
                },
                "upload_volume_id": "vol-0123456789abcdef0",
                "initialize_upload_volume": initialize,
            }
            expression = f"base64encode(templatefile({json.dumps(str(template))}, {json.dumps(parameters)}))\n"
            with tempfile.TemporaryDirectory(prefix="bootstrap-render-") as workspace:
                result = subprocess.run(
                    [terraform, "console", "-no-color"], input=expression,
                    text=True, capture_output=True, cwd=workspace, check=True,
                )
            cls.rendered[initialize] = base64.b64decode(json.loads(result.stdout)).decode()

    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory(prefix="bootstrap-storage-")
        self.addCleanup(self.workspace.cleanup)
        self.directory = Path(self.workspace.name)
        self.uploads = self.directory / "uploads"
        self.uploads.mkdir()
        self.log = self.directory / "commands"

    def run_storage(self, initialize=False, **state):
        script = self.rendered[initialize].split("<<'STORAGE'\n", 1)[1].split("\nSTORAGE\n", 1)[0]
        script = script.replace("/srv/laravel/uploads", str(self.uploads))
        environment = os.environ | {
            "TEST_DEVICE": str(self.directory / "fake-device"),
            "TEST_SERIAL": "vol0123456789abcdef0",
            "TEST_TYPES": "disk",
            "TEST_FILESYSTEM": "ext4",
            "TEST_SIGNATURE": "",
            "TEST_MOUNTED": "1",
            "TEST_MOUNT_UUID": "fixture-uuid",
            "TEST_UPLOADS": str(self.uploads),
            "TEST_LOG": str(self.log),
        } | state
        # Device discovery, mounting and root-only ownership are the boundary
        # doubles. Directory creation and permission checks use real temp files.
        doubles = r'''
test() {
    if [[ $# == 2 && $1 == -b && $2 == "$TEST_DEVICE" ]]; then return 0; fi
    builtin test "$@"
}
lsblk() {
    if [[ $* == *--nodeps* ]]; then printf '%s %s\n' "$TEST_DEVICE" "$TEST_SERIAL";
    else printf '%s\n' "$TEST_TYPES"; fi
}
blkid() {
    if [[ $* == *TYPE* ]]; then
        [[ -n $TEST_FILESYSTEM ]] || return 2
        printf '%s\n' "$TEST_FILESYSTEM"
    else printf '%s\n' fixture-uuid; fi
}
wipefs() { printf '%s' "$TEST_SIGNATURE"; }
mkfs.ext4() { printf 'format %s\n' "$*" >> "$TEST_LOG"; }
mountpoint() { return "$TEST_MOUNTED"; }
mount() { printf 'mount %s\n' "$*" >> "$TEST_LOG"; }
findmnt() { printf '%s\n' "$TEST_MOUNT_UUID"; }
chown() { printf 'ownership %s\n' "$*" >> "$TEST_LOG"; }
install() {
    [[ $* == "-d -o 33 -g 33 -m 0750 $TEST_UPLOADS/private $TEST_UPLOADS/public" ]] || return 1
    command install -d -m 0750 "$TEST_UPLOADS/private" "$TEST_UPLOADS/public"
}
seq() { printf '1\n'; }
sleep() { :; }
'''
        return subprocess.run(["bash", "-c", doubles + script], text=True, capture_output=True, env=environment)

    def commands(self):
        return self.log.read_text() if self.log.exists() else ""

    def test_complete_rendered_bootstrap_has_valid_shell_syntax(self):
        for initialize, script in self.rendered.items():
            with self.subTest(initialize=initialize):
                subprocess.run(["bash", "-n"], input=script, text=True, check=True)

    def test_existing_ext4_is_mounted_without_formatting(self):
        result = self.run_storage()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("format", self.commands())
        self.assertIn("nodev,nosuid,noexec", self.commands())
        self.assertIn("ownership 33:33", self.commands())
        for directory in (self.uploads, self.uploads / "private", self.uploads / "public"):
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o750)

    def test_empty_volume_requires_explicit_initialization(self):
        result = self.run_storage(TEST_FILESYSTEM="")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("explicit initialization approval", result.stderr)
        self.assertEqual(self.commands(), "")

    def test_explicit_initialization_formats_only_the_selected_empty_volume(self):
        result = self.run_storage(initialize=True, TEST_FILESYSTEM="")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"format -q {self.directory / 'fake-device'}", self.commands())
        self.assertEqual(self.commands().count("format "), 1)

    def test_unsafe_devices_are_refused_before_format_or_mount(self):
        for state in (
            {"TEST_SERIAL": "vol-some-other-volume"},
            {"TEST_TYPES": "disk\npart"},
            {"TEST_FILESYSTEM": "", "TEST_SIGNATURE": "gpt"},
            {"TEST_FILESYSTEM": "xfs"},
        ):
            with self.subTest(state=state):
                self.log.unlink(missing_ok=True)
                result = self.run_storage(initialize=True, **state)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(self.commands(), "")

    def test_already_mounted_wrong_volume_is_refused(self):
        result = self.run_storage(TEST_MOUNTED="0", TEST_MOUNT_UUID="wrong-uuid")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.commands(), "")


if __name__ == "__main__":
    unittest.main()
