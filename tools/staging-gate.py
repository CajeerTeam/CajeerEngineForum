#!/usr/bin/env python3
"""Verify a completed disposable clean-install staging run and the same-dist TLS host.

This is an admission gate for *observed* test output, never a simulated pass.
The staging workflow must execute release_acceptance.py and preserve its exit
status before calling this command.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile


def require(condition, message):
    if not condition:
        raise ValueError(message)


def digest(path):
    require(path.is_file() and not path.is_symlink(), 'Missing or linked staging input: ' + path.name)
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def check(candidate, key, host_path, log_path, logdir, version, commit):
    require(re.fullmatch(r'[a-f0-9]{40}', commit) is not None, 'Invalid Git commit')
    require(version in ('0.9.9', '1.0.0-rc.1', '1.0.0'), 'Unexpected final candidate version')
    require(key.is_file() and not key.is_symlink(), 'Missing independent trusted key')
    package = candidate / f'cajeerengine-{version}-dist.zip'
    dist_sha = digest(package)
    subprocess.run(['php', str(Path(__file__).resolve().parent / 'verify-signed-release.php'), str(candidate), str(key)], check=True, timeout=120, stdout=subprocess.DEVNULL)
    with zipfile.ZipFile(package) as archive:
        release = json.loads(archive.read('cajeerengine/release.json'))
        require(release['edition_version'] == version and release['edition'] == 'forum'
                and release['upstream_version'] == '1.3.0', 'Staging archive internal identity mismatch')
    host = json.loads(host_path.read_text())
    require(host.get('ok') is True and host.get('version') == version
            and host.get('artifact_sha256') == dist_sha
            and host.get('profile') == 'nginx-tls-fpm-pgsql', 'Wrong physical TLS host evidence')
    require(isinstance(host.get('checks'), dict) and len(host['checks']) >= 8
            and all(value is True for value in host['checks'].values()), 'Host security checks incomplete')
    require(host.get('load', {}).get('errors') == 0 and host['load'].get('requests', 0) >= 400,
            'Host TLS load baseline insufficient')
    stdout = log_path.read_text()
    require('PASS physical dist acceptance' in stdout, 'Physical staging clean-install did not complete')
    required = ('initial-install.log', 'initial-migrate.log', 'initial-postcheck.log', 'initial-install-check.log')
    log_hashes = {name: digest(logdir / name) for name in required}
    require(len(list(logdir.glob('*.log'))) >= len(required), 'Staging exercise lacks execution evidence')
    return {
        'schema': 1, 'ok': True, 'version': version, 'commit': commit,
        'artifact_sha256': dist_sha, 'host_report_sha256': digest(host_path),
        'staging_stdout_sha256': digest(log_path), 'command_log_sha256': log_hashes,
        'checks': {
            'signed_exact_dist': True, 'actual_clean_install_and_migration': True,
            'http_auth_acl_search_media_and_moderation': True,
            'rollback_and_failed_migration_recovery': True,
            'same_dist_nginx_https_php_fpm_postgresql': True,
        },
        'scope': 'Disposable clean-install HTTP acceptance, migration rollback and same-signed-dist Linux HTTPS host',
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--candidate', type=Path, required=True)
    p.add_argument('--trusted-public-key', type=Path, required=True)
    p.add_argument('--host', type=Path, required=True)
    p.add_argument('--stdout', type=Path, required=True)
    p.add_argument('--logs', type=Path, required=True)
    p.add_argument('--version', required=True)
    p.add_argument('--commit', required=True)
    p.add_argument('--report', type=Path, required=True)
    a = p.parse_args()
    result = check(a.candidate, a.trusted_public_key, a.host, a.stdout, a.logs, a.version, a.commit)
    a.report.parent.mkdir(parents=True, exist_ok=True)
    temp = a.report.with_name('.' + a.report.name + '.tmp')
    with temp.open('x') as stream:
        json.dump(result, stream, indent=2, sort_keys=True)
        stream.write('\n')
        stream.flush()
        import os
        os.fsync(stream.fileno())
    temp.replace(a.report)
    print('PASS final staging physical acceptance bound to SHA256', result['artifact_sha256'])


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print('FAIL staging:', error, file=sys.stderr)
        raise SystemExit(1)
