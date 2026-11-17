#!/usr/bin/env python3
"""Fail-closed release certification: GitHub matrix, host, audit and signed ZIP identity."""
import argparse
import base64
import hashlib
import hmac
import os
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('acceptance', ROOT / 'tools/production-acceptance.py')
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)
spec_ci = importlib.util.spec_from_file_location('ci_jobs', ROOT / 'tools/collect-ci-jobs.py')
ci = importlib.util.module_from_spec(spec_ci)
spec_ci.loader.exec_module(ci)


def check(condition, message):
    if not condition:
        raise ValueError(message)


def read(path):
    def invalid(_):
        raise ValueError('Invalid JSON number')
    with path.open() as stream:
        return json.load(stream, parse_constant=invalid)


def sha(path):
    check(path.is_file() and not path.is_symlink(), 'Missing/nonregular evidence: ' + path.name)
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def inventory(directory, version):
    names = []
    for mode in ('source', 'dist'):
        base = f'cajeerengine-{version}-{mode}'
        names.extend((base + '.zip', base + '.zip.sha256', base + '.zip.sig', base + '.manifest.json'))
    names.extend((f'cajeerengine-{version}-artifacts.json', 'ci-release.pub',
                  'production-acceptance.json', 'dependency-audit.json', 'ci-jobs.json',
                  'dr-sqlite.json', 'dr-pg17.json', 'dr-pg18.json', 'dr-s3.json', 'staging-acceptance.json'))
    return {name: sha(directory / name) for name in names}


def check_audit(audit):
    check(isinstance(audit, dict) and 'advisories' in audit and 'abandoned' in audit, 'Incomplete Composer audit')
    check(audit['advisories'] in ({}, []) and audit['abandoned'] in ({}, []), 'Composer advisories/abandoned dependencies found')
    check(not audit.get('ignored-advisories') and not audit.get('ignored-severity') and not audit.get('error'), 'Composer audit includes exceptions or errors')


def validate_host(directory, version, hashes):
    host = read(directory / 'production-acceptance.json')
    dist_sha = hashes[f'cajeerengine-{version}-dist.zip']
    check(host.get('schema') == 1 and host.get('ok') is True and host.get('version') == version
          and host.get('profile') == 'nginx-tls-fpm-pgsql' and host.get('artifact_sha256') == dist_sha,
          'Host acceptance missing or not for exact signed dist')
    check(host.get('global_rate_limit') == 10000 and host.get('auth_rate_limit') == 20, 'Wrong host load profile')
    check(host.get('checks') == dict.fromkeys(a.SECURITY, True) and a.load_pass(host.get('load', {})),
          'Host security/load acceptance failed')
    check_audit(read(directory / 'dependency-audit.json'))
    return host



DR_CHECKS = frozenset((
    'pre_snapshot_write_acknowledged', 'backup_under_http_load', 'offsite_readback_verified',
    'source_installation_destroyed', 'catalog_free_offsite_discovery',
    'offsite_fetch_after_host_loss', 'isolated_recovery_and_maintenance',
    'https_acl_media_search_identity_write', 'end_to_end_rto_rpo',
))


def validate_disaster_recovery(directory, version, commit, digest):
    for label, driver, transport in (
        ('sqlite','sqlite','file'), ('pg17','pgsql','file'),
        ('pg18','pgsql','file'), ('s3','sqlite','s3'),
    ):
        row = read(directory / ('dr-' + label + '.json'))
        check(row.get('schema') == 1 and row.get('ok') is True
              and row.get('version') == version and row.get('commit') == commit
              and row.get('artifact_sha256') == digest
              and row.get('driver') == driver and row.get('transport') == transport,
              'Missing or unbound physical DR report: ' + label)
        check(row.get('checks') == dict.fromkeys(DR_CHECKS, True),
              'Missing physical DR check: ' + label)
        check(isinstance(row.get('load'), dict)
              and isinstance(row['load'].get('reads'), int) and row['load']['reads'] > 0
              and isinstance(row['load'].get('writes'), int) and row['load']['writes'] >= 0,
              'No real backup workload: ' + label)
        rto, age = row.get('service_rto_seconds'), row.get('rpo_upper_bound_seconds')
        check(type(rto) in (int, float) and 0 < rto <= 1800
              and type(age) in (int, float) and 0 <= age <= 3600,
              'Unacceptable HTTPS service RTO / snapshot age: ' + label)


def validate_jobs(directory, commit, repo, run_id, attempt):
    report = read(directory / 'ci-jobs.json')
    needs = {name: {'result': 'success'} for name in ci.EXPECTED}
    ci.validate_report(report, commit, run_id, attempt, repo, needs)
    return report


def verify_signatures(directory, trusted_key=None):
    supplied = directory / 'ci-release.pub'
    check(supplied.is_file() and not supplied.is_symlink(), 'Missing CI public key')
    if trusted_key is not None:
        def raw_key(path):
            check(path.is_file() and not path.is_symlink(), 'Trusted signing key is missing or a symlink')
            key = base64.b64decode(path.read_bytes().strip(), validate=True)
            check(len(key) == 32, 'Invalid Ed25519 public key length')
            return key
        check(hmac.compare_digest(raw_key(supplied), raw_key(trusted_key)),
              'CI signature key is not the trusted publishing key')
    subprocess.run(['php', str(ROOT / 'tools/verify-signed-release.php'), str(directory), str(trusted_key or supplied)],
                   check=True, stdout=subprocess.DEVNULL)


def validate_staging(directory, version, commit, hashes):
    stage = read(directory / 'staging-acceptance.json')
    host_sha = hashes['production-acceptance.json']
    dist_sha = hashes[f'cajeerengine-{version}-dist.zip']
    required = {'signed_exact_dist', 'actual_clean_install_and_migration',
                'http_auth_acl_search_media_and_moderation',
                'rollback_and_failed_migration_recovery',
                'same_dist_nginx_https_php_fpm_postgresql'}
    check(stage.get('schema') == 1 and stage.get('ok') is True
          and stage.get('version') == version and stage.get('commit') == commit
          and stage.get('artifact_sha256') == dist_sha
          and stage.get('host_report_sha256') == host_sha,
          'Final staging did not verify the signed candidate and same-host TLS evidence')
    check(stage.get('checks') == dict.fromkeys(required, True), 'Staging coverage is incomplete')
    logs = stage.get('command_log_sha256')
    check(isinstance(logs, dict) and len(logs) >= 4
          and all(isinstance(digest, str) and re.fullmatch('[0-9a-f]{64}', digest) for digest in logs.values())
          and re.fullmatch('[0-9a-f]{64}', stage.get('staging_stdout_sha256', '')) is not None,
          'Staging execution logs are missing')


def validate(directory, version, commit, repo, run_id, attempt, trusted_key=None):
    check(re.fullmatch('[0-9a-f]{40}', commit) is not None, 'Invalid commit')
    check(isinstance(run_id, int) and run_id > 0 and isinstance(attempt, int) and attempt > 0, 'Invalid run identity')
    hashes = inventory(directory, version)
    validate_host(directory, version, hashes)
    validate_disaster_recovery(directory, version, commit, hashes[f'cajeerengine-{version}-dist.zip'])
    validate_staging(directory, version, commit, hashes)
    jobs = validate_jobs(directory, commit, repo, run_id, attempt)
    verify_signatures(directory, trusted_key)
    return hashes, jobs


def seal(directory, version, commit, repo, run_id, attempt):
    hashes, jobs = validate(directory, version, commit, repo, run_id, attempt)
    report = {
        'schema': 2, 'ok': True, 'version': version, 'commit': commit,
        'repository': repo, 'run_id': run_id, 'attempt': attempt,
        'scope': 'CI matrix + signed source/dist + audit + disposable host + final staging + filesystem/S3 HTTPS disaster recovery',
        'signing': 'trusted-release-key' if jobs.get('tagged_release') else 'ci-ephemeral',
        'sha256': hashes,
        'ci_jobs': jobs['jobs'],
    }
    # The public key is pinned by its hash; production publication independently
    # verifies it against the operator-approved external trusted key.
    a.write_report(directory / 'certification.json', report)


def verify(directory, version, commit, repo, run_id, attempt, trusted_key=None):
    hashes, jobs = validate(directory, version, commit, repo, run_id, attempt, trusted_key)
    report = read(directory / 'certification.json')
    check(report.get('signing') == ('trusted-release-key' if jobs.get('tagged_release') else 'ci-ephemeral'), 'Wrong signing scope')
    check(report.get('schema') == 2 and report.get('ok') is True and report.get('version') == version
          and report.get('commit') == commit and report.get('repository') == repo
          and report.get('run_id') == run_id and report.get('attempt') == attempt
          and report.get('sha256') == hashes and report.get('ci_jobs') == jobs['jobs'],
          'Certification does not bind this exact run, commit, CI matrix and signed files')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('seal', 'verify'))
    parser.add_argument('directory', type=Path)
    parser.add_argument('--trusted-key', type=Path, help='Required publisher-owned Ed25519 trust anchor for publication')
    args = parser.parse_args()
    version = read(ROOT / 'release.json')['edition_version']
    commit = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
    jobs = read(args.directory / 'ci-jobs.json')
    repo, run_id, attempt = jobs['repository'], jobs['run_id'], jobs['attempt']
    check(isinstance(repo, str) and repo.lower() == 'cajeerteam/cajeerengineforum', 'Wrong release repository')
    if args.action == 'seal':
        check(args.trusted_key is None, 'Sealing is controlled by the CI run, not a supplied trust key')
        check('GITHUB_RUN_ID' in os.environ and int(os.environ['GITHUB_RUN_ID']) == run_id, 'Sealing outside CI run prohibited')
        check(int(os.environ.get('GITHUB_RUN_ATTEMPT', '0')) == attempt and os.environ.get('GITHUB_SHA') == commit, 'CI commit/attempt mismatch')
        seal(args.directory, version, commit, repo, run_id, attempt)
    else:
        verify(args.directory, version, commit, repo, run_id, attempt, args.trusted_key)
    print('PASS certification', args.action, version, commit, run_id, attempt)


if __name__ == '__main__':
    try:
        main()
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError, TypeError) as exc:
        print('FAIL certification:', exc, file=sys.stderr)
        raise SystemExit(1)
