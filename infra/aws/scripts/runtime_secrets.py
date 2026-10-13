"""Explicit host-only Secrets Manager delivery; default invocation is offline."""

import argparse
import base64
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys


SECRET_ARN = re.compile(r'arn:aws:secretsmanager:([a-z0-9-]+):[0-9]{12}:secret:[A-Za-z0-9/_+=.@-]+-[A-Za-z0-9]{6}')
USERNAME = re.compile(r'[a-z][a-z0-9_]{0,62}')
PUBLIC_KEYS = {'APP_URL', 'DB_HOST', 'DB_DATABASE', 'DB_SSLROOTCERT'}
SECRET_KEYS = {'APP_KEY', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_PASSWORD'}


def single_line(value):
    return isinstance(value, str) and bool(value) and len(value.encode()) <= 16384 and not any(ord(char) < 32 or ord(char) == 127 for char in value)


def laravel_string(value):
    # Laravel Env recognizes reserved types before removing one pair of outer
    # quotes. Raw Compose must retain this extra pair to recover a literal string.
    if value.lower() in {'true', '(true)', 'false', '(false)', 'empty', '(empty)', 'null', '(null)'} or (len(value) >= 2 and value[0] in {'"', "'"} and value[-1] == value[0]):
        return '"' + value + '"'
    return value


def validate_config(config):
    if not isinstance(config, dict) or set(config) != {'secret_arn', 'region', 'database_username', 'environment'}:
        raise ValueError('Invalid runtime metadata')
    arn = SECRET_ARN.fullmatch(config.get('secret_arn', ''))
    username = config.get('database_username', '')
    environment = config.get('environment')
    if not arn or arn[1] != config.get('region') or not USERNAME.fullmatch(username) or username in {'postgres', 'rdsadmin', 'rds_superuser'}:
        raise ValueError('Invalid secret or application identity')
    if not isinstance(environment, dict) or set(environment) != PUBLIC_KEYS or not all(single_line(value) for value in environment.values()):
        raise ValueError('Invalid public runtime configuration')
    if len(environment['APP_URL']) > 270 or len(environment['DB_HOST']) > 253 or not re.fullmatch(r'https://[a-z0-9.-]+(?::[0-9]+)?', environment['APP_URL']) or not re.fullmatch(r'[A-Za-z0-9.-]+', environment['DB_HOST']):
        raise ValueError('Invalid production endpoint')
    if not USERNAME.fullmatch(environment['DB_DATABASE']) or len(environment['DB_SSLROOTCERT']) > 4096 or not environment['DB_SSLROOTCERT'].startswith('/'):
        raise ValueError('Invalid PostgreSQL configuration')


def validate_secret(secret, username):
    if not isinstance(secret, dict) or not {'APP_KEY', 'DB_USERNAME', 'DB_PASSWORD'} <= set(secret) or not set(secret) <= SECRET_KEYS:
        raise ValueError('Invalid runtime secret schema')
    if not all(single_line(value) for value in secret.values()) or secret['DB_USERNAME'] != username:
        raise ValueError('Invalid runtime credential')
    key = secret['APP_KEY']
    try:
        decoded = base64.b64decode(key.removeprefix('base64:'), validate=True)
    except ValueError:
        raise ValueError('Invalid application key') from None
    if len(decoded) != 32 or key != 'base64:' + base64.b64encode(decoded).decode():
        raise ValueError('Invalid application key')


def fetch(config, aws_cli):
    # Ignore shared profiles and exported credentials. This host boundary uses
    # only its EC2 instance profile, never an operator's permanent access keys.
    environment = {key: value for key, value in os.environ.items() if not key.startswith('AWS_')}
    environment.update(AWS_CONFIG_FILE='/dev/null', AWS_SHARED_CREDENTIALS_FILE='/dev/null',
                       BOTO_CONFIG='/dev/null', AWS_EC2_METADATA_DISABLED='false', AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off')
    result = subprocess.run([aws_cli, 'secretsmanager', 'get-secret-value', '--region', config['region'],
                             '--secret-id', config['secret_arn'], '--version-stage', 'AWSCURRENT', '--output', 'json',
                             '--no-cli-pager', '--cli-connect-timeout', '5', '--cli-read-timeout', '15'],
                            env=environment, capture_output=True, text=True, timeout=30, check=False)
    if result.returncode or len(result.stdout.encode()) > 131072:
        raise ValueError('Runtime secret fetch failed')
    try:
        response = json.loads(result.stdout)
        secret = json.loads(response['SecretString'])
    except (ValueError, KeyError, TypeError):
        raise ValueError('Invalid runtime secret response') from None
    validate_secret(secret, config['database_username'])
    return secret


def private_file(descriptor):
    information = os.fstat(descriptor)
    if not stat.S_ISREG(information.st_mode) or stat.S_IMODE(information.st_mode) != 0o600 or information.st_uid != os.geteuid() or information.st_nlink != 1:
        raise ValueError('Runtime file must be private and owned by the invoking host identity')


def deliver(config, output, aws_cli):
    validate_config(config)
    output = Path(output)
    directory = os.open(output.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    temporary = None
    try:
        info = os.fstat(directory)
        if stat.S_IMODE(info.st_mode) != 0o700 or info.st_uid != os.geteuid():
            raise ValueError('Runtime directory must be private and owned by the invoking host identity')
        fcntl.flock(directory, fcntl.LOCK_EX)
        previous_key = None
        try:
            descriptor = os.open(output.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        except FileNotFoundError:
            descriptor = None
        if descriptor is not None:
            with os.fdopen(descriptor) as previous:
                private_file(previous.fileno())
                content = previous.read(65537)
            keys = [line.removeprefix('APP_KEY=') for line in content.splitlines() if line.startswith('APP_KEY=')]
            if len(content.encode()) > 65536 or len(keys) != 1:
                raise ValueError('Existing application key is missing or ambiguous')
            previous_key = keys[0]
        secret = fetch(config, aws_cli)
        if previous_key is not None and previous_key != secret['APP_KEY']:
            raise ValueError('Application key rotation requires a separate recovery procedure')
        environment = {'APP_ENV': 'production', 'APP_DEBUG': 'false', 'SESSION_SECURE_COOKIE': 'true',
                       'DB_CONNECTION': 'pgsql', 'DB_PORT': '5432', 'DB_SSLMODE': 'verify-full',
                       'CACHE_STORE': 'database', 'SESSION_DRIVER': 'database', 'QUEUE_CONNECTION': 'database',
                       **config['environment'], **secret}
        literal_keys = SECRET_KEYS | {'DB_DATABASE'}
        content = ''.join(f'{key}={laravel_string(value) if key in literal_keys else value}\n' for key, value in environment.items()).encode()
        temporary = '.runtime-' + secrets.token_hex(16)
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory)
        with os.fdopen(descriptor, 'wb') as current:
            os.fchmod(current.fileno(), 0o600)
            current.write(content)
            current.flush()
            os.fsync(current.fileno())
        os.replace(temporary, output.name, src_dir_fd=directory, dst_dir_fd=directory)
        temporary = None
        os.fsync(directory)
    finally:
        if temporary is not None:
            os.unlink(temporary, dir_fd=directory)
        os.close(directory)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--aws-cli', default='/usr/local/bin/aws')
    parser.add_argument('--execute', action='store_true', help='Explicitly authorize the selected host runtime secret read')
    args = parser.parse_args()
    try:
        config = json.loads(args.config.read_text())
        validate_config(config)
        if args.execute:
            if not Path(args.aws_cli).is_absolute():
                raise ValueError('Use the approved absolute AWS CLI v2 executable path')
            deliver(config, args.output, args.aws_cli)
            print('Private runtime file prepared; container restart remains an explicit operator action')
        else:
            print('Runtime metadata valid; offline preview performed no AWS read or file write')
    except (ValueError, TypeError, OSError, subprocess.SubprocessError):
        print('Runtime secret preparation failed; existing runtime file was preserved', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
