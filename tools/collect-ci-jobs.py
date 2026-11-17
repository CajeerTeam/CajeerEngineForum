#!/usr/bin/env python3
"""Fetch and attest *all* actual GitHub Actions matrix jobs for the current attempt."""
import argparse
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from urllib.request import Request, urlopen

# Counts refer to actual jobs (not the logical `needs` aggregate of a matrix).
EXPECTED = {
    'test': 1, 'postgres': 2, 'mysql-search': 2, 'browser': 2,
    'release': 3, 'production-host': 1, 'container-runtime': 2,
    'container-upgrade': 1, 'backup-s3': 1, 'monitoring': 1,
    'release-candidate': 1, 'staging': 1,
}

def check(value, message):
    if not value:
        raise ValueError(message)


def get_json(path, *, repository, token, api):
    request = Request(f'{api}/repos/{repository}/{path}', headers={
        'Accept': 'application/vnd.github+json', 'Authorization': 'Bearer ' + token,
        'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'CajeerForum-Certification',
    })
    with urlopen(request, timeout=25) as response:
        return json.load(response)


def validate_report(report, expected_commit, expected_run, expected_attempt, repository, needs):
    check(report.get('schema') == 1 and report.get('repository') == repository, 'Wrong CI repository')
    check(report.get('commit') == expected_commit and re.fullmatch(r'[0-9a-f]{40}', expected_commit), 'Wrong checkout commit')
    check(report.get('run_id') == expected_run and report.get('attempt') == expected_attempt, 'Wrong CI run/attempt')
    check(report.get('ok') is True, 'CI run not successful')
    check(isinstance(report.get('ref'), str) and isinstance(report.get('tagged_release'), bool), 'Missing CI ref/signing scope')
    check(not report['tagged_release'] or (report['event'] == 'push' and re.fullmatch(r'refs/tags/v(?:\d+\.\d+\.\d+|1\.0\.0-rc\.1)', report['ref'])), 'Not an approved tag event')
    jobs = report.get('jobs')
    check(isinstance(jobs, list), 'Missing CI jobs')
    actual = {name: 0 for name in EXPECTED}
    identifiers = set()
    for job in jobs:
        check(isinstance(job, dict) and isinstance(job.get('id'), int) and job['id'] not in identifiers, 'Duplicate/malformed CI job ID')
        identifiers.add(job['id'])
        name = job.get('name')
        check(isinstance(name, str), 'Invalid CI job name')
        logical = name.split(' (', 1)[0]
        check(logical in EXPECTED, 'Unexpected/missing job in certification: ' + name)
        check(job.get('conclusion') == 'success', 'CI job failed or unfinished: ' + name)
        steps = job.get('steps')
        check(isinstance(steps, list) and steps, 'Missing CI step results: ' + name)
        for step in steps:
            check(isinstance(step, dict) and isinstance(step.get('name'), str)
                  and step.get('conclusion') in ('success', 'skipped'), 'Failed/incomplete CI step in ' + name)
        actual[logical] += 1
    check(actual == EXPECTED, 'Missing/duplicate CI matrix jobs: ' + str(actual))
    check(isinstance(needs, dict) and set(needs) == set(EXPECTED), 'CI needs incomplete')
    check(all(isinstance(v, dict) and v.get('result') == 'success' for v in needs.values()), 'Logical CI gate did not pass')
    check(report.get('needs') == {name: 'success' for name in EXPECTED}, 'CI needs do not match report')
    return True


def collect(repository, run_id, attempt, commit, token, api, needs):
    check(re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository) is not None, 'Bad repository')
    check(token, 'GitHub actions:read token missing')
    run = get_json(f'actions/runs/{run_id}', repository=repository, token=token, api=api)
    check(run.get('id') == run_id and run.get('run_attempt') == attempt, 'Wrong GitHub Actions run')
    check(run.get('status') in ('in_progress', 'queued'), 'Run already finalized or invalid')
    # For pull_request the checkout may be a synthetic merge commit. GITHUB_SHA is the
    # trustworthy checkout identity, while head_sha is the PR branch's head commit.
    if run.get('event') != 'pull_request':
        check(run.get('head_sha') == commit, 'GitHub Actions HEAD mismatch')
    all_jobs = []
    page = 1
    while True:
        payload = get_json(f'actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100&page={page}', repository=repository, token=token, api=api)
        batch = payload.get('jobs')
        check(isinstance(batch, list), 'Invalid jobs response')
        all_jobs.extend(batch)
        check(len(all_jobs) <= 1000, 'Too many CI jobs')
        if len(batch) < 100:
            break
        page += 1
    jobs = []
    for item in all_jobs:
        if item.get('name') in ('certification', 'forum-required'):
            continue
        jobs.append({'id': item.get('id'), 'name': item.get('name'), 'conclusion': item.get('conclusion'),
                     'html_url': item.get('html_url'), 'started_at': item.get('started_at'),
                     'completed_at': item.get('completed_at'),
                     'steps': [{'name': step.get('name'), 'conclusion': step.get('conclusion')}
                               for step in item.get('steps', [])]})
    jobs.sort(key=lambda job: (job['name'], job['id']))
    report = {
        'schema': 1, 'ok': True, 'repository': repository, 'run_id': run_id,
        'attempt': attempt, 'commit': commit, 'event': run.get('event'),
        'head_sha': run.get('head_sha'), 'ref': os.environ['GITHUB_REF'],
        'tagged_release': os.environ['GITHUB_REF'].startswith('refs/tags/v'),
        'needs': {name: item.get('result') for name, item in needs.items()},
        'jobs': jobs,
    }
    validate_report(report, commit, run_id, attempt, repository, needs)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    parser.add_argument('needs_json', type=Path)
    args = parser.parse_args()
    needs = json.loads(args.needs_json.read_text())
    repo = os.environ['GITHUB_REPOSITORY']
    run_id = int(os.environ['GITHUB_RUN_ID'])
    attempt = int(os.environ['GITHUB_RUN_ATTEMPT'])
    commit = os.environ['GITHUB_SHA']
    root = Path(__file__).resolve().parent.parent
    import subprocess
    check(subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip() == commit, 'Checkout differs from GITHUB_SHA')
    report = collect(repo, run_id, attempt, commit, os.environ.get('GH_TOKEN', ''), os.environ.get('GITHUB_API_URL', 'https://api.github.com').rstrip('/'), needs)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode='w', dir=args.output.parent, prefix='.jobs-', delete=False) as out:
        json.dump(report, out, indent=2, sort_keys=True)
        out.write('\n')
        temp = Path(out.name)
    os.replace(temp, args.output)
    print('PASS', len(report['jobs']), 'required matrix jobs for', commit)

if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError) as exc:
        print('FAIL CI jobs:', exc, file=sys.stderr)
        raise SystemExit(1)
