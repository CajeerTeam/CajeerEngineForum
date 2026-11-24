#!/usr/bin/env python3
"""Fetch the exact successful, signed CI candidate and bind physical receipts to a run attempt."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('gate', ROOT/'tools/release-candidate-gate.py')
gate = importlib.util.module_from_spec(spec); spec.loader.exec_module(gate)


def digest(path):
    if not path.is_file() or path.is_symlink():
        raise ValueError('Missing regular evidence: '+path.name)
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def validate_candidate(run, jobs, repo, commit, run_id):
    gate.validate_ci_run(run, repo, commit)
    if (run['id'] != run_id or jobs.get('run_id') != run_id
            or jobs.get('attempt') != run['run_attempt'] or jobs.get('commit') != commit
            or jobs.get('repository') != repo):
        raise ValueError('Candidate artifact does not belong to the selected CI attempt')


def fetch(args):
    repo, commit = os.environ['GITHUB_REPOSITORY'], os.environ['GITHUB_SHA']
    run = json.loads(subprocess.check_output(['gh','api',f'repos/{repo}/actions/runs/{args.ci_run}']))
    gate.validate_ci_run(run, repo, commit)
    if run['id'] != args.ci_run:
        raise ValueError('Wrong CI run')
    args.directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    subprocess.run(['gh','run','download',str(args.ci_run),'--repo',repo,'--name','forum-certified-release','--dir',str(args.directory)],check=True)
    jobs = json.loads((args.directory/'ci-jobs.json').read_text())
    validate_candidate(run,jobs,repo,commit,args.ci_run)
    subprocess.run(['php',str(ROOT/'tools/verify-signed-release.php'),str(args.directory),str(args.trusted_key)],check=True)
    subprocess.run(['python3',str(ROOT/'tools/final-audit.py'),'--directory',str(args.directory),'--trusted-public-key',str(args.trusted_key),'--version',args.version,'--commit',commit],check=True)
    subprocess.run(['python3',str(ROOT/'tools/certification-evidence.py'),'verify',str(args.directory),'--trusted-key',str(args.trusted_key)],check=True)
    (args.directory/'qualification-ci-run.json').write_text(json.dumps(run)+'\n')


def receipt(args):
    run=json.loads((args.directory/'qualification-ci-run.json').read_text())
    jobs=json.loads((args.directory/'ci-jobs.json').read_text())
    repo, commit = os.environ['GITHUB_REPOSITORY'], os.environ['GITHUB_SHA']
    validate_candidate(run,jobs,repo,commit,run['id'])
    names=gate.REPORTS[args.kind]
    row={'schema':1,'kind':args.kind,'commit':commit,'repository':repo,'version':args.version,
         'run_id':int(os.environ['GITHUB_RUN_ID']),'attempt':int(os.environ['GITHUB_RUN_ATTEMPT']),
         'ci_run_id':run['id'],'ci_attempt':run['run_attempt'],
         'dist_sha256':digest(args.directory/f'cajeerengine-{args.version}-dist.zip'),
         'reports':{name:digest(args.reports/name) for name in names}}
    from tempfile import NamedTemporaryFile
    with NamedTemporaryFile('w',dir=args.reports,delete=False) as stream:
        tmp=Path(stream.name);json.dump(row,stream,sort_keys=True);stream.write('\n');stream.flush();os.fsync(stream.fileno())
    os.replace(tmp,args.reports/'qualification-receipt.json')


def main():
    p=argparse.ArgumentParser(description=__doc__);sub=p.add_subparsers(dest='action',required=True)
    for action in ('fetch','receipt'):
        s=sub.add_parser(action);s.add_argument('--directory',type=Path,required=True);s.add_argument('--version',required=True)
        if action=='fetch':
            s.add_argument('--ci-run',type=int,required=True);s.add_argument('--trusted-key',type=Path,required=True)
        else:
            s.add_argument('--reports',type=Path,required=True);s.add_argument('--kind',choices=gate.REPORTS,required=True)
    a=p.parse_args(); {'fetch':fetch,'receipt':receipt}[a.action](a)


if __name__=='__main__': main()
