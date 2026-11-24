#!/usr/bin/env python3
"""Validate independently executed GitHub workflow identities and crash receipts.

Use only JSON fetched from GitHub Actions API by the protected publishing job.
Physical load reports are revalidated separately against the signed distribution.
"""
import argparse
import hashlib
import json
import math
from pathlib import Path
import re

WORKFLOWS = {
    'ci': ('forum-ci.yml', 'push'),
    'physical': ('forum-long-qualification.yml', 'workflow_dispatch'),
    'drained': ('forum-chaos-update.yml', 'workflow_dispatch'),
    'activating': ('forum-chaos-update.yml', 'workflow_dispatch'),
    'autonomous': ('forum-autonomous-qualification.yml', 'workflow_dispatch'),
}


def require(ok, reason):
    if not ok:
        raise ValueError(reason)


def read(path):
    return json.loads(path.read_text())


REPORTS = {'physical': ('10k.json','100k.json','1m-soak.json'),
           'drained': ('recovery.json',), 'activating': ('recovery.json',), 'autonomous': ('autonomous.json',)}


def validate_ci_run(row, repo, commit):
    require(row.get('path') == '.github/workflows/forum-ci.yml' and row.get('event') == 'push'
            and row.get('head_sha') == commit and row.get('head_branch') == 'main'
            and row.get('repository', {}).get('full_name') == repo
            and row.get('head_repository', {}).get('full_name') == repo
            and row.get('status') == 'completed' and row.get('conclusion') == 'success'
            and type(row.get('id')) is int and row['id'] > 0
            and type(row.get('run_attempt')) is int and row['run_attempt'] > 0,
            'wrong or unsuccessful candidate CI run')


def validate_receipt(directory, kind, rows, repo, commit, version, digest):
    receipt = read(directory/'qualification-receipt.json')
    expected = {'schema':1,'kind':kind,'commit':commit,'repository':repo,'version':version,
                'run_id':rows[kind]['id'],'attempt':rows[kind]['run_attempt'],
                'ci_run_id':rows['ci']['id'],'ci_attempt':rows['ci']['run_attempt'],
                'dist_sha256':digest}
    require(all(receipt.get(k)==v for k,v in expected.items()), kind+': receipt identity mismatch')
    reports = {}
    for name in REPORTS[kind]:
        path=directory/name
        require(path.is_file() and not path.is_symlink(), kind+': missing evidence')
        with path.open('rb') as stream:
            reports[name]=hashlib.file_digest(stream,'sha256').hexdigest()
    require(receipt.get('reports')==reports, kind+': evidence changed after qualification')


def validate_runs(rows, repo, commit):
    require(re.fullmatch(r'[a-f0-9]{40}', commit) is not None, 'invalid commit')
    require(set(rows) == set(WORKFLOWS), 'missing qualification workflow')
    ids = []
    for name, (workflow, event) in WORKFLOWS.items():
        row = rows[name]
        require(row.get('path') == '.github/workflows/'+workflow and row.get('event') == event,
                name+': wrong workflow/event')
        require(row.get('head_sha') == commit and row.get('head_branch') == 'main'
                and row.get('repository', {}).get('full_name') == repo
                and row.get('head_repository', {}).get('full_name') == repo,
                name+': wrong repository, ref or commit')
        require(row.get('status') == 'completed' and row.get('conclusion') == 'success', name+': unsuccessful run')
        require(type(row.get('id')) is int and row['id'] > 0, name+': invalid run identity')
        ids.append(row['id'])
    require(len(set(ids)) == len(ids), 'duplicate qualification run identity')


def previous_version(version):
    if version == '1.5.0':
        return '1.4.11'
    require(re.fullmatch(r'[0-9]+\.[0-9]+\.[1-9][0-9]*', version) is not None,
            'unknown release predecessor; explicitly configure minor/major upgrades')
    return '.'.join(version.split('.')[:-1]+[str(int(version.split('.')[-1])-1)])


def validate_recovery(row, checkpoint, commit, version, digest):
    previous = previous_version(version)
    require(row.get('schema') == 1 and row.get('ok') is True and row.get('actual_operator_sigkill') is True,
            checkpoint+': missing actual recovery')
    require(row.get('checkpoint') == checkpoint and row.get('commit') == commit
            and row.get('source_version') == previous and row.get('target_version') == version
            and row.get('target_sha256') == digest, checkpoint+': recovery identity mismatch')
    require(re.fullmatch(r'[a-f0-9]{64}', row.get('source_sha256','')) is not None
            and row['source_sha256'] != digest, checkpoint+': invalid source digest')
    require(row.get('recovery_state') == ('rolled_back' if checkpoint == 'drained' else 'committed'), checkpoint+': incorrect recovery state')
    require(row.get('preserved_fixture') is True and row.get('recovered_version') == (previous if checkpoint == 'drained' else version), checkpoint+': missing verified HTTP data recovery')
    if version in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7','1.5.8','1.5.9','1.5.10','1.5.11'):
        identity=row.get('deployment_identity',{})
        require(row.get('signed_deployment_verified') is True
                and re.fullmatch(r'sha256:[a-f0-9]{64}',identity.get('image','')) is not None
                and re.fullmatch(r'[a-f0-9]{64}',identity.get('manifest_sha256','')) is not None,
                checkpoint+': missing signed deployment verification')
    seconds = row.get('recovery_seconds')
    require(type(seconds) in (int,float) and math.isfinite(seconds) and 0 < seconds <= 900,
            checkpoint+': recovery exceeded time budget')


def validate_autonomous(row, commit, digest):
    require(row.get('schema') == 1 and row.get('ok') is True and row.get('commit') == commit
            and row.get('candidate_sha256') == digest and row.get('source_version') == '1.5.9'
            and row.get('target_version') == '1.5.10', 'autonomous: wrong candidate or published transition')
    require(all(row.get(k) is True for k in ('actual_autonomous_update','canonical_github','backup_rehearsed',
            'installed_integrity','preserved_fixture','no_duplicate_apply')), 'autonomous: missing live proof')
    require(type(row.get('manual_requests')) is int and row['manual_requests'] == 0, 'autonomous: manual approval present')
    for k in ('github_dist_sha256','key_id'):
        require(isinstance(row.get(k),str) and re.fullmatch(r'[a-f0-9]{64}',row[k]) is not None, 'autonomous: invalid '+k)
    require(row['github_dist_sha256'] != digest, 'autonomous: published baseline must differ from unpublished candidate')
    require(isinstance(row.get('transaction_id'),str) and re.fullmatch(r'\d{8}-\d{6}-[a-f0-9]{16}',row['transaction_id']), 'autonomous: invalid transaction')
    sec=row.get('seconds')
    require(type(sec) in (int,float) and math.isfinite(sec) and 0<sec<=900, 'autonomous: update duration')
    during=row.get('during',{})
    require(set(during)=={'ok','maintenance','errors'} and all(type(v) is int and v>=0 for v in during.values())
            and during['ok']>0 and during['maintenance']>0 and during['errors']==0, 'autonomous: maintenance/availability failure')
    for phase in ('before','after'):
        load=row.get(phase,{})
        elapsed=load.get('elapsed_seconds'); requests=load.get('requests'); rps=load.get('requests_per_second')
        require(type(elapsed) in (int,float) and math.isfinite(elapsed) and 60<=elapsed<=90
                and type(requests) is int and requests>=300 and type(rps) in (int,float) and math.isfinite(rps)
                and rps>=5 and abs(requests/elapsed-rps)<=0.01, 'autonomous: insufficient '+phase+' load')
        operations=load.get('operations',{})
        require(set(operations)=={'thread','page'}, 'autonomous: missing routes')
        total=0
        for op in operations.values():
            n=op.get('requests'); errors=op.get('errors'); p95=op.get('p95_upper_ms'); types=op.get('error_types',{})
            require(type(n) is int and n>=150 and type(errors) is int and 0<=errors<=n and errors/n<=.001
                    and type(p95) in (int,float) and math.isfinite(p95) and 0<p95<=500
                    and isinstance(types,dict) and all(type(v) is int and v>=0 for v in types.values())
                    and sum(types.values())==errors and not types.get('semantic_mismatch'), 'autonomous: SLO failed')
            total+=n
        require(total==requests, 'autonomous: inconsistent request counts')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for name in ('repo','commit','version'):
        p.add_argument('--'+name, required=True)
    for name in ('metadata','physical','drained','activating','dist'):
        p.add_argument('--'+name, type=Path, required=True)
    p.add_argument('--load-directory', type=Path)
    p.add_argument('--autonomous', type=Path, required=True)
    a = p.parse_args()
    rows = {name:read(a.metadata/(name+'.json')) for name in WORKFLOWS}
    validate_runs(rows,a.repo,a.commit)
    with a.dist.open('rb') as stream:
        digest = hashlib.file_digest(stream,'sha256').hexdigest()
    physical = read(a.physical)
    require(physical.get('schema') == 1 and physical.get('ok') is True and physical.get('edition_version') == a.version
            and physical.get('commit') == a.commit and physical.get('dist_sha256') == digest
            and set(physical.get('profiles',{})) == {'10k','100k','1m-soak'}, 'physical certificate identity mismatch')
    if a.version in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7','1.5.8','1.5.9','1.5.10','1.5.11'):
        validate_ci_run(rows['ci'],a.repo,a.commit)
        require(a.load_directory is not None, 'raw physical evidence directory required')
        for kind, directory in (('physical',a.load_directory),('drained',a.drained.parent),('activating',a.activating.parent)):
            validate_receipt(directory,kind,rows,a.repo,a.commit,a.version,digest)
    validate_receipt(a.autonomous.parent,'autonomous',rows,a.repo,a.commit,a.version,digest)
    autonomous = read(a.autonomous)
    validate_autonomous(autonomous,a.commit,digest)
    drained, activating = read(a.drained), read(a.activating)
    validate_recovery(drained,'drained',a.commit,a.version,digest)
    validate_recovery(activating,'activating',a.commit,a.version,digest)
    require(drained['source_sha256'] == activating['source_sha256'] == autonomous['github_dist_sha256'], 'different published signed rollback baseline')
    require(autonomous['key_id'] == read(Path(str(a.dist)+'.sig')).get('key_id'), 'different publisher key')
    print('PASS: physical load and both crash recovery checkpoints bound to the exact release')


if __name__ == '__main__':
    main()
