#!/usr/bin/env python3
"""Separate, read-only publication audit of completed certification evidence.

Does not import the CI evidence sealer or trust its 'ok' bit. Rehashes all
sealed inputs and signed archives, checks immutable run identity, job topology,
physical staging witness and independently provisioned Ed25519 verification key.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

EXPECTED = {
    'release-candidate': 1, 'test': 1, 'postgres': 2, 'mysql-search': 2,
    'browser': 2, 'release': 3, 'production-host': 1, 'container-runtime': 2,
    'container-upgrade': 1, 'backup-s3': 1, 'monitoring': 1, 'staging': 1,
}
REQUIRED_STAGING = {
    'signed_exact_dist', 'actual_clean_install_and_migration',
    'http_auth_acl_search_media_and_moderation',
    'rollback_and_failed_migration_recovery',
    'same_dist_nginx_https_php_fpm_postgresql',
}


def demand(condition, reason):
    if not condition:
        raise ValueError(reason)


def read(path):
    demand(path.is_file() and not path.is_symlink(), 'Missing/linked evidence ' + path.name)
    def invalid(_):
        raise ValueError('Nonfinite JSON constant')
    return json.loads(path.read_text(), parse_constant=invalid)


def sha(path):
    demand(path.is_file() and not path.is_symlink(), 'Missing/linked file ' + path.name)
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def audit(directory, trusted_key, version, commit):
    demand(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?', version) is not None, 'Unsupported final-audit edition')
    demand(re.fullmatch(r'[0-9a-f]{40}', commit) is not None, 'Invalid checkout commit')
    demand(trusted_key.is_file() and not trusted_key.is_symlink(), 'External trusted key required')
    report = read(directory / 'certification.json')
    demand(report.get('schema') == 2 and report.get('ok') is True and report.get('version') == version
           and report.get('commit') == commit and report.get('repository', '').lower() == 'cajeerteam/cajeerengineforum',
           'Wrong certificate provenance')
    jobs = read(directory / 'ci-jobs.json')
    demand(jobs.get('commit') == commit and jobs.get('run_id') == report.get('run_id')
           and jobs.get('attempt') == report.get('attempt') and jobs.get('ok') is True, 'CI provenance differs')
    demand(report.get('ci_jobs') == jobs.get('jobs') and jobs.get('needs') == dict.fromkeys(EXPECTED, 'success'),
           'CI matrix differs from certification')
    counts = dict.fromkeys(EXPECTED, 0)
    ids = set()
    for job in jobs['jobs']:
        name = job['name'].split(' (', 1)[0]
        demand(name in EXPECTED and job.get('conclusion') == 'success' and job.get('id') not in ids, 'Invalid CI job')
        ids.add(job['id'])
        demand(job.get('steps') and all(s.get('conclusion') in ('success', 'skipped') for s in job['steps']), 'Invalid CI steps')
        counts[name] += 1
    demand(counts == EXPECTED, 'Incomplete CI matrix')
    tagged = jobs.get('tagged_release') is True
    production_branch = jobs.get('event') == 'push' and jobs.get('ref') == 'refs/heads/main'
    if tagged:
        demand(jobs.get('event') == 'push' and jobs.get('ref') == 'refs/tags/v' + version,
               'Wrong tagged certification provenance')
    if tagged or production_branch:
        demand(report.get('signing') == 'trusted-release-key', 'Production candidate must use trusted release key')
    else:
        demand(report.get('signing') == 'ci-ephemeral', 'Untrusted branch certification')
    expected = set()
    for mode in ('source', 'dist'):
        base = f'cajeerengine-{version}-{mode}'
        expected.update((base+'.zip', base+'.zip.sha256', base+'.zip.sig', base+'.manifest.json'))
    expected.update((f'cajeerengine-{version}-artifacts.json', 'ci-release.pub',
                    'production-acceptance.json', 'dependency-audit.json', 'ci-jobs.json',
                    'dr-sqlite.json', 'dr-pg17.json', 'dr-pg18.json', 'dr-s3.json',
                    'staging-acceptance.json'))
    sealed = report.get('sha256')
    demand(isinstance(sealed, dict) and set(sealed) == expected, 'Missing/extraneous sealed input')
    for name in expected:
        demand(re.fullmatch(r'[a-f0-9]{64}', sealed[name]) is not None
               and sha(directory/name) == sealed[name], 'Changed evidence: '+name)
    demand(sha(trusted_key) == sha(directory / 'ci-release.pub'), 'Unapproved signing public key')
    subprocess.run(['php', str(Path(__file__).resolve().parent / 'verify-signed-release.php'), str(directory), str(trusted_key)],
                   check=True, timeout=120, stdout=subprocess.DEVNULL)
    index = read(directory / f'cajeerengine-{version}-artifacts.json')
    demand(index.get('version') == version and len(index.get('artifacts', [])) == 2, 'Invalid artifact index')
    dist_sha = sealed[f'cajeerengine-{version}-dist.zip']
    for mode in ('source','dist'):
        filename=f'cajeerengine-{version}-{mode}.zip'
        checksum=(directory/(filename+'.sha256')).read_text().strip()
        demand(checksum == sealed[filename]+'  '+filename, 'Wrong artifact checksum')
        with zipfile.ZipFile(directory/filename) as archive:
            identity=json.loads(archive.read('cajeerengine/release.json'))
            demand(identity.get('edition')=='forum' and identity.get('edition_version')==version
                   and identity.get('upstream_version')==(Path(__file__).resolve().parents[1]/'VERSION').read_text().strip(), 'Wrong packaged forum identity')
    stage = read(directory/'staging-acceptance.json')
    host = read(directory/'production-acceptance.json')
    demand(stage.get('schema')==1 and stage.get('ok') is True and stage.get('version') == version
           and stage.get('commit') == commit and stage.get('artifact_sha256') == dist_sha
           and stage.get('host_report_sha256') == sealed['production-acceptance.json'], 'Staging proof not bound to release')
    demand(stage.get('checks') == dict.fromkeys(REQUIRED_STAGING, True), 'Staging checks missing')
    demand(isinstance(stage.get('command_log_sha256'),dict) and len(stage['command_log_sha256']) >= 4
           and all(re.fullmatch('[0-9a-f]{64}',x or '') for x in stage['command_log_sha256'].values())
           and re.fullmatch('[0-9a-f]{64}',stage.get('staging_stdout_sha256','')), 'Staging lacks recorded physical execution')
    demand(host.get('ok') is True and host.get('artifact_sha256') == dist_sha
           and host.get('version') == version and all(v is True for v in host.get('checks',{}).values()), 'Host TLS evidence failed')
    dependency = read(directory/'dependency-audit.json')
    demand(dependency.get('advisories') in ({}, []) and dependency.get('abandoned') in ({}, [])
           and 'advisories' in dependency and 'abandoned' in dependency
           and not dependency.get('ignored-advisories') and not dependency.get('ignored-severity'), 'Unsafe dependency audit')
    for label in ('sqlite','pg17','pg18','s3'):
        dr=read(directory/('dr-'+label+'.json'))
        demand(dr.get('ok') is True and dr.get('artifact_sha256') == dist_sha
               and dr.get('commit') == commit and dr.get('version')==version
               and all(value is True for value in dr.get('checks',{}).values())
               and len(dr.get('checks',{})) >= 5, 'Invalid recovery evidence '+label)
    return {'ok': True, 'version': version, 'commit': commit, 'dist_sha256': dist_sha,
            'run_id': jobs['run_id'], 'attempt': jobs['attempt'], 'matrix_jobs': len(jobs['jobs']),
            'signed_files_verified': len(expected), 'staging_sha256': sealed['staging-acceptance.json']}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--directory',type=Path,required=True)
    p.add_argument('--trusted-public-key',type=Path,required=True)
    p.add_argument('--version',required=True)
    p.add_argument('--commit',required=True)
    a=p.parse_args()
    print(json.dumps(audit(a.directory,a.trusted_public_key,a.version,a.commit),sort_keys=True))

if __name__=='__main__':
    try: main()
    except (ValueError,KeyError,TypeError,OSError,subprocess.CalledProcessError,zipfile.BadZipFile) as e:
        print('FAIL independent final audit:',str(e),file=sys.stderr)
        raise SystemExit(1)
