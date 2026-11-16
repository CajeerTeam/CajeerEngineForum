#!/usr/bin/env python3
"""Generate Prometheus/Alertmanager JSON (valid YAML), without copying secrets into config."""
import argparse
import json
import os
import re
import stat
import tempfile
from pathlib import Path
from urllib.parse import urlsplit


def write_atomic(path, content, mode=0o644):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ValueError(f'Unsafe destination: {path.name}')
    fd, name = tempfile.mkstemp(prefix='.monitor-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as file:
            file.write(content)
            file.flush()
            os.fsync(file.fileno())
            os.fchmod(file.fileno(), mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def secret(directory, name, minimum, owner=None):
    path = directory / name
    s = path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_mode & 0o007 or s.st_mode & 0o022:
        raise ValueError(f'{name}: require a protected regular file, no world access or group write')
    data = path.read_text().strip()
    if len(data) < minimum or '\n' in data or '\r' in data or '\x00' in data:
        raise ValueError(f'{name}: invalid value')
    return data


def configure(directory, target, ca):
    if not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?:[0-9]{1,5}', target):
        raise ValueError('target must be a DNS name or IPv4 address with port')
    host, port = target.rsplit(':', 1)
    if not 1 <= int(port) <= 65535 or '..' in host:
        raise ValueError('invalid target')
    directory = Path(directory)
    if not directory.is_absolute() or directory.is_symlink() or not directory.is_dir() or directory.stat().st_mode & 0o022:
        raise ValueError('config directory must be absolute, existing, protected from group/world writes')
    secret(directory, 'metrics-token', 32)
    secret(directory, 'grafana-password', 16)
    hook = urlsplit(secret(directory, 'alert-webhook', 12))
    if hook.scheme != 'https' or not hook.hostname or hook.username or hook.password or hook.fragment:
        raise ValueError('alert-webhook must be a HTTPS URL without userinfo/fragment')
    cert = Path(ca).read_bytes()
    if b'-----BEGIN CERTIFICATE-----' not in cert or b'PRIVATE KEY' in cert:
        raise ValueError('CA bundle must contain public certificates only')
    write_atomic(directory / 'forum-ca.pem', cert)
    tls = {'ca_file': '/etc/prometheus/forum-ca.pem', 'insecure_skip_verify': False}
    prometheus = {'global': {'scrape_interval': '30s', 'scrape_timeout': '25s', 'evaluation_interval': '30s'},
                  'rule_files': ['/etc/prometheus/rules/core.yml','/etc/prometheus/rules/forum.yml'],
                  'alerting': {'alertmanagers': [{'static_configs': [{'targets': ['alertmanager:9093']}]}]},
                  'scrape_configs': [{'job_name': 'cajeerengine', 'scheme': 'https', 'metrics_path': '/metrics',
                                      'authorization': {'type': 'Bearer', 'credentials_file': '/run/secrets/metrics-token'},
                                      'tls_config': tls, 'static_configs': [{'targets': [target]}]}]}
    alertmanager = {'route': {'receiver': 'operator', 'group_by': ['alertname', 'instance'], 'group_wait': '30s',
                              'group_interval': '5m', 'repeat_interval': '4h'},
                    'receivers': [{'name': 'operator', 'webhook_configs': [{'url_file': '/run/secrets/alert-webhook', 'send_resolved': True,
                       'http_config': {'tls_config': {'ca_file': '/etc/alertmanager/forum-ca.pem', 'insecure_skip_verify': False}}}]}]}
    for name, data in [('prometheus.json', prometheus), ('alertmanager.json', alertmanager)]:
        write_atomic(directory / name, (json.dumps(data, indent=2)+'\n').encode())


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config-dir', required=True)
    p.add_argument('--target', required=True)
    p.add_argument('--ca-file', default='/etc/ssl/certs/ca-certificates.crt')
    args = p.parse_args()
    try:
        configure(args.config_dir, args.target, args.ca_file)
    except (ValueError, OSError) as error:
        p.exit(1, f'Configuration rejected: {error}\n')
    print('Monitoring configuration created (secrets remain separate).')
