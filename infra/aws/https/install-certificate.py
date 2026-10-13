#!/usr/bin/env python3
"""Install already-issued certificates offline. Never contacts ACME, DNS or AWS."""
import argparse
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import uuid


def checked(command):
    return subprocess.run(command, check=True, capture_output=True).stdout


def install(arguments):
    # Validate a private snapshot, not files an external issuer may replace concurrently.
    with tempfile.TemporaryDirectory(prefix="tls-validation-") as directory:
        certificate = pathlib.Path(directory) / "fullchain.pem"
        key = pathlib.Path(directory) / "privkey.pem"
        shutil.copyfile(arguments.fullchain, certificate)
        shutil.copyfile(arguments.private_key, key)
        original_certificate, original_key = arguments.fullchain, arguments.private_key
        arguments.fullchain, arguments.private_key = certificate, key
        try:
            install_snapshot(arguments)
        finally:
            arguments.fullchain, arguments.private_key = original_certificate, original_key


def install_snapshot(arguments):
    os.umask(0o077)
    domain = arguments.domain
    if len(domain) > 253 or not re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?", domain):
        raise ValueError("Use an explicit lowercase DNS hostname, without wildcards, IPs or URL syntax.")
    certificate = str(arguments.fullchain)
    key = str(arguments.private_key)
    # Some OpenSSL x509 versions return success even for a -checkhost mismatch.
    # Treat the provided leaf as the explicit temporary trust anchor solely to
    # verify name, validity and server purpose; issuing-CA trust is the owner's duty.
    checked(["openssl", "verify", "-trusted", certificate, "-partial_chain", "-verify_hostname", domain, "-purpose", "sslserver", certificate])
    # Require a SAN: CN-only certificates are not accepted by modern browsers.
    extensions = checked(["openssl", "x509", "-in", certificate, "-noout", "-ext", "subjectAltName"])
    if b"DNS:" not in extensions:
        raise ValueError("A DNS Subject Alternative Name is required.")
    checked(["openssl", "x509", "-in", certificate, "-noout", "-checkend", "86400"])
    public_certificate = checked(["openssl", "x509", "-in", certificate, "-pubkey", "-noout"])
    public_key = checked(["openssl", "pkey", "-in", key, "-pubout", "-passin", "pass:"])
    if public_certificate != public_key:
        raise ValueError("Certificate and key do not match.")
    directory = arguments.directory
    if directory.is_symlink():
        raise ValueError("The certificate directory must not be a symlink.")
    if directory.exists():
        metadata = directory.stat()
        if not directory.is_dir() or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o022:
            raise ValueError("The directory must be owned by the installer and not writable by group/others.")
    else:
        directory.mkdir(mode=0o750, parents=False)
    os.chown(directory, -1, arguments.nginx_gid)
    os.chmod(directory, 0o750)
    current = directory / "current"
    if current.exists() and not current.is_symlink():
        raise ValueError("The current certificate must be an installer-managed symlink.")
    version = directory / ("certificate-" + uuid.uuid4().hex)
    version.mkdir(mode=0o750)
    os.chmod(version, 0o750)
    os.chown(version, -1, arguments.nginx_gid)
    for source, name in [(arguments.fullchain, "fullchain.pem"), (arguments.private_key, "privkey.pem")]:
        destination = version / name
        shutil.copyfile(source, destination)
        os.chmod(destination, 0o640)
        os.chown(destination, -1, arguments.nginx_gid)
    candidate = directory / (".current-" + uuid.uuid4().hex)
    candidate.symlink_to(version.name, target_is_directory=True)
    os.replace(candidate, current)
    print("Certificate pair installed; validate Nginx before an explicitly approved reload.")


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--domain", required=True)
parser.add_argument("--mechanism", required=True, choices=["provided", "acme-webroot", "acme-dns"])
parser.add_argument("--fullchain", required=True, type=pathlib.Path)
parser.add_argument("--private-key", required=True, type=pathlib.Path)
parser.add_argument("--directory", required=True, type=pathlib.Path)
parser.add_argument("--nginx-gid", required=True, type=int)
args = parser.parse_args()
try:
    if args.nginx_gid < 0:
        raise ValueError("Use an explicit non-negative Nginx group ID.")
    os.umask(0o077)
    install(args)
except (ValueError, OSError, subprocess.SubprocessError):
    print("Certificate installation rejected: validate domain/SAN/expiry/key and restricted directory ownership. No reload performed.", file=sys.stderr)
    sys.exit(1)
