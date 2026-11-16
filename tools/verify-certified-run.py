#!/usr/bin/env python3
"""Recheck published GitHub CI run/attempt against sealed local evidence before release."""
import argparse
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('ci', ROOT / 'tools/collect-ci-jobs.py')
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)


def gh(path):
    return json.loads(subprocess.check_output(['gh', 'api', path], text=True))


def verify(report, repository, tag, commit, request=gh):
    ci.check(re.fullmatch(r'v\d+\.\d+\.\d+', tag) is not None, 'Bad release tag')
    ci.check(report.get('repository', '').lower() == repository.lower() and report.get('commit') == commit,
             'Published CI evidence does not match repo/commit')
    ci.check(report.get('tagged_release') is True and report.get('ref') == 'refs/tags/' + tag,
             'CI certificate was not produced for this release tag')
    ci.check(report.get('event') == 'push', 'Release certification was not a tag push')
    run_id, attempt = report['run_id'], report['attempt']
    run = request(f'repos/{repository}/actions/runs/{run_id}')
    ci.check(run.get('id') == run_id and run.get('run_attempt') == attempt and
             run.get('head_sha') == commit and run.get('status') == 'completed' and
             run.get('conclusion') == 'success' and run.get('event') == 'push' and
             run.get('head_branch') == tag, 'Remote CI run did not pass on exact release tag')
    items = []
    page = 1
    while True:
        response = request(f'repos/{repository}/actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100&page={page}')
        batch = response.get('jobs')
        ci.check(isinstance(batch, list), 'Invalid remote CI jobs response')
        items.extend(batch)
        ci.check(len(items) <= 1000, 'CI job response too large')
        if len(batch) < 100:
            break
        page += 1
    final = {j['name']: j for j in items if j.get('name') in ('certification', 'forum-required')}
    ci.check(set(final) == {'certification', 'forum-required'} and
             all(j.get('conclusion') == 'success' for j in final.values()), 'Remote certification/final branch gate missing')
    actual = sorted(({'id': j.get('id'), 'name': j.get('name'), 'conclusion': j.get('conclusion'),
                      'html_url': j.get('html_url'), 'started_at': j.get('started_at'),
                      'completed_at': j.get('completed_at'),
                      'steps': [{'name': step.get('name'), 'conclusion': step.get('conclusion')}
                                for step in j.get('steps', [])]}
                     for j in items if j.get('name') not in final), key=lambda j: (j['name'], j['id']))
    ci.check(actual == report.get('jobs'), 'Remote CI matrix differs from certified job results')
    needs = {name: {'result': 'success'} for name in ci.EXPECTED}
    ci.validate_report(report, commit, run_id, attempt, repository, needs)
    return len(actual)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('ci_jobs', type=Path)
    parser.add_argument('repository')
    parser.add_argument('tag')
    parser.add_argument('commit')
    args = parser.parse_args()
    count = verify(json.loads(args.ci_jobs.read_text()), args.repository, args.tag, args.commit)
    print('PASS remote certified GitHub run:', count, 'CI jobs')

if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError) as error:
        print('FAIL remote GitHub certification:', error, file=sys.stderr)
        raise SystemExit(1)
