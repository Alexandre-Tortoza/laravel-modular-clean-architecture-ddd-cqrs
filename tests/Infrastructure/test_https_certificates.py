#!/usr/bin/env python3
"""Offline certificate installation regression tests: no ACME, DNS or Docker."""
import os
import pathlib
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
INSTALLER = ROOT / "infra/aws/https/install-certificate.py"

class CertificateInstallation(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = pathlib.Path(self.temporary.name)
        self.cert = self.directory / "fullchain.pem"
        self.key = self.directory / "privkey.pem"
        self.target = self.directory / "installed"
        self.issue()

    def tearDown(self):
        self.temporary.cleanup()

    def issue(self, name="tls.smoke.invalid"):
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", f"/CN={name}", "-addext", f"subjectAltName=DNS:{name}", "-keyout", str(self.key), "-out", str(self.cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def install(self, domain="tls.smoke.invalid", mechanism="provided"):
        return subprocess.run(["python3", str(INSTALLER), "--domain", domain, "--mechanism", mechanism, "--fullchain", str(self.cert), "--private-key", str(self.key), "--directory", str(self.target), "--nginx-gid", str(os.getgid())], capture_output=True, text=True)

    def test_installs_and_atomically_rotates_a_matching_certificate(self):
        self.assertEqual(self.install().returncode, 0)
        first = (self.target / "current").resolve()
        self.assertEqual((first / "privkey.pem").stat().st_mode & 0o777, 0o640)
        self.assertEqual(first.stat().st_mode & 0o777, 0o750)
        self.issue()
        self.assertEqual(self.install(mechanism="acme-webroot").returncode, 0)
        self.assertNotEqual(first, (self.target / "current").resolve())
        self.assertTrue((first / "privkey.pem").is_file())

    def test_invalid_domains_and_mechanisms_leave_no_installation(self):
        for domain in ["", "localhost", "*.smoke.invalid", "bad.invalid;return 200;", "https://tls.smoke.invalid", "-bad.invalid", "tls..invalid", "127.0.0.1"]:
            with self.subTest(domain=domain):
                self.assertNotEqual(self.install(domain=domain).returncode, 0)
                self.assertFalse(self.target.exists())
        self.assertNotEqual(self.install(mechanism="automatic").returncode, 0)
        self.assertFalse(self.target.exists())

    def test_wrong_hostname_or_key_cannot_replace_the_current_certificate(self):
        self.assertEqual(self.install().returncode, 0)
        first = (self.target / "current").resolve()
        self.assertNotEqual(self.install(domain="other.smoke.invalid").returncode, 0)
        self.assertEqual(first, (self.target / "current").resolve())
        self.issue("other.smoke.invalid")
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(first, (self.target / "current").resolve())
        self.issue()
        subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-out", str(self.key)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(first, (self.target / "current").resolve())

    def test_rejects_cn_only_and_near_expiry_pairs(self):
        for days, san in [(2, False), (1, True)]:
            command = ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", str(days), "-subj", "/CN=tls.smoke.invalid", "-keyout", str(self.key), "-out", str(self.cert)]
            if san:
                command += ["-addext", "subjectAltName=DNS:tls.smoke.invalid"]
            subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            self.assertNotEqual(self.install().returncode, 0)
            self.assertFalse(self.target.exists())

    def test_rejects_unsafe_target_without_exposing_private_material(self):
        self.target.mkdir(mode=0o777)
        self.target.chmod(0o777)
        result = self.install()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("PRIVATE KEY", result.stdout + result.stderr)
        self.assertFalse((self.target / "current").exists())
        self.target.rmdir()
        self.target.symlink_to(self.directory, target_is_directory=True)
        self.assertNotEqual(self.install().returncode, 0)

if __name__ == "__main__":
    unittest.main()
