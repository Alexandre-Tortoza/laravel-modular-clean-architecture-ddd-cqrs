"""Explicit host health observation; default preview performs no HTTP/AWS calls."""

import argparse
import datetime
import json
from pathlib import Path
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parent))
from runtime_secrets import instance_profile_environment


def validate(config):
    expected = {'environment', 'region', 'app_url', 'certificate_authority_file', 'release_revision', 'log_group_name', 'log_stream_name'}
    if not isinstance(config, dict) or set(config) != expected:
        raise ValueError('Invalid observation metadata')
    if not re.fullmatch(r'[a-z][a-z0-9-]{0,31}', config['environment']) or not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)+-[0-9]+', config['region']):
        raise ValueError('Invalid environment or region')
    if len(config['app_url']) > 270 or not re.fullmatch(r'https://[a-z0-9.-]+(?::[0-9]+)?', config['app_url']):
        raise ValueError('Use the approved HTTPS application origin without credentials or paths')
    if not re.fullmatch(r'[0-9a-f]{40}', config['release_revision']):
        raise ValueError('Use a full approved source revision')
    if config['log_group_name'] != f'/template/{config["environment"]}/runtime' or config['log_stream_name'] != 'health':
        raise ValueError('Use the selected existing application health log stream')
    ca = config['certificate_authority_file']
    if ca is not None and (not isinstance(ca, str) or not Path(ca).is_absolute()):
        raise ValueError('Use an explicitly selected absolute CA file or the host trust store')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def status(url, ca_file):
    try:
        context = ssl.create_default_context(cafile=ca_file)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=context))
        with opener.open(urllib.request.Request(url, headers={'Accept': 'application/json'}), timeout=5) as response:
            # Headers/body may contain data or diagnostics. Never read, publish or log them.
            return response.status
    except urllib.error.HTTPError as error:
        error.close()
        return error.code
    except (urllib.error.URLError, OSError, ValueError):
        return 0


def collect(config):
    validate(config)
    up = status(config['app_url'] + '/up', config['certificate_authority_file'])
    ready = status(config['app_url'] + '/ready', config['certificate_authority_file'])
    return {'metrics': {'Liveness': int(up == 200), 'Readiness': int(ready == 200)},
            'event': {'event': 'health', 'release_revision': config['release_revision'], 'up_status': up, 'ready_status': ready}}


def publish(config, observation, aws_cli):
    validate(config)
    event = observation.get('event', {})
    metrics = observation.get('metrics', {})
    if (set(observation) != {'event', 'metrics'} or set(event) != {'event', 'release_revision', 'up_status', 'ready_status'} or
            event['event'] != 'health' or event['release_revision'] != config['release_revision'] or
            any(type(event[field]) is not int or not 0 <= event[field] <= 599 for field in ['up_status', 'ready_status']) or
            set(metrics) != {'Liveness', 'Readiness'} or any(type(value) is not int or value not in {0, 1} for value in metrics.values()) or
            metrics['Liveness'] != int(event['up_status'] == 200) or metrics['Readiness'] != int(event['ready_status'] == 200)):
        raise ValueError('Publish only the whitelisted numeric health observation')
    if not Path(aws_cli).is_absolute():
        raise ValueError('Use the approved absolute AWS CLI v2 path')
    timestamp = datetime.datetime.now(datetime.timezone.utc).isoformat()
    metrics = {'Namespace': f'Template/{config["environment"]}/Runtime',
               'MetricData': [{'MetricName': name, 'Dimensions': [{'Name': 'Environment', 'Value': config['environment']}],
                               'Value': value, 'Unit': 'Count', 'Timestamp': timestamp}
                              for name, value in observation['metrics'].items()]}
    logs = {'logGroupName': config['log_group_name'], 'logStreamName': config['log_stream_name'],
            'logEvents': [{'timestamp': int(time.time() * 1000), 'message': json.dumps(observation['event'], separators=(',', ':'))}]}
    for service, operation, payload in [('cloudwatch', 'put-metric-data', metrics), ('logs', 'put-log-events', logs)]:
        result = subprocess.run([aws_cli, service, operation, '--region', config['region'], '--cli-input-json', 'file:///dev/stdin',
                                 '--no-cli-pager', '--cli-connect-timeout', '5', '--cli-read-timeout', '15'],
                                input=json.dumps(payload), env=instance_profile_environment(), capture_output=True,
                                text=True, timeout=30, check=False)
        if result.returncode:
            raise ValueError('Selected observation publication failed')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--aws-cli', default='/usr/local/bin/aws')
    parser.add_argument('--execute', action='store_true', help='Explicitly authorize the selected HTTPS probe and AWS telemetry writes')
    args = parser.parse_args()
    try:
        config = json.loads(args.config.read_text())
        validate(config)
        if args.execute:
            observation = collect(config)
            publish(config, observation, args.aws_cli)
            print('Selected health metrics and redacted status event published')
            return 0 if all(observation['metrics'].values()) else 1
        print('Observation metadata valid; offline preview performed no HTTP/AWS call')
        return 0
    except (ValueError, TypeError, OSError, subprocess.SubprocessError):
        print('Observation failed; verify selected health endpoints/identity locally; diagnostics redacted', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
