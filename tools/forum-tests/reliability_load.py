#!/usr/bin/env python3
"""Disposable multi-worker HTTP load smoke. Real API/SSR; never target production."""
import argparse
import concurrent.futures
import json
import math
import time
import urllib.request
from pathlib import Path
from run import Client, server, ADMIN, ADMIN_PASSWORD


def percentile(values, q):
    return round(sorted(values)[max(0, math.ceil(len(values)*q)-1)], 3)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--report', type=Path, required=True)
    p.add_argument('--clients', type=int, default=8)
    p.add_argument('--requests', type=int, default=160)
    p.add_argument('--workers', type=int, default=4)
    args = p.parse_args()
    if not 2 <= args.clients <= 32 or not 40 <= args.requests <= 400 or not 2 <= args.workers <= 8:
        p.error('clients 2–32, requests 40–400, workers 2–8 (bounded smoke)')
    report = args.report.resolve(); report.mkdir(parents=True, exist_ok=True)
    with server(report, workers=args.workers) as base:
        c = Client(base)
        token = c.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
        board = c.call('GET', '/forum')['boards'][0]['id']
        threads = [c.call('POST', f'/forum/boards/{board}/threads', 201,
                          {'title': f'Reliability topic {i}', 'body': f'loadmarker initial {i}'}, token=token)['id'] for i in range(4)]
        # Concurrent read/write mix. A unique marker makes lost/duplicate writes detectable.
        def request(i):
            thread = threads[i % len(threads)]; client = Client(base); begin = time.perf_counter()
            kind = ['thread','latest','search','ssr','write'][i % 5]
            # Limit writes to 20 in the disposable test, not bypassing anti-spam.
            if (kind == 'write' and i >= 100) or (kind == 'search' and i >= 250): kind = 'thread'
            result = None; error = None
            try:
                if kind == 'thread':
                    assert client.call('GET', f'/forum/threads/{thread}')['posts'][0]['body'].startswith('loadmarker initial')
                elif kind == 'latest':
                    assert threads[0] in [r['id'] for r in client.call('GET', '/forum/latest?cursor=')['items']]
                elif kind == 'search':
                    assert threads[0] in [r['id'] for r in client.call('GET', '/forum/search?q=loadmarker')]
                elif kind == 'ssr':
                    with urllib.request.urlopen(base + f'/forum/threads/{thread}', timeout=20) as response:
                        assert response.status == 200 and b'loadmarker initial' in response.read()
                else:
                    result = client.call('POST', f'/forum/threads/{thread}/posts', 201,
                                         {'body': f'reliability-write-{i}'}, token=token)['id']
            except Exception as e:
                error = f'{type(e).__name__}: {e}'
            return {'kind': kind, 'ms': (time.perf_counter()-begin)*1000, 'error': error, 'id': result, 'i': i, 'thread': thread}
        begin = time.perf_counter()
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.clients) as pool:
            rows = list(pool.map(request, range(args.requests)))
        elapsed = time.perf_counter()-begin
        errors = [r for r in rows if r['error']]
        summary = {'requests': len(rows), 'clients': args.clients, 'php_workers': args.workers,
                   'duration_seconds': round(elapsed, 3), 'rps': round(len(rows)/elapsed, 2), 'errors': errors,
                   'operations': {}}
        for kind in sorted(set(r['kind'] for r in rows)):
            times = [r['ms'] for r in rows if r['kind']==kind]
            summary['operations'][kind] = {'count': len(times), 'p50_ms': percentile(times,.50), 'p95_ms': percentile(times,.95), 'p99_ms': percentile(times,.99)}
        (report/'load.json').write_text(json.dumps(summary, indent=2))
        assert not errors, f'{len(errors)} failed requests; see load.json'
        # Every confirmed publication must exist exactly once, not just return 201.
        for t in threads:
            posts = c.call('GET', f'/forum/threads/{t}')['posts']
            expected = {r['id']: f"reliability-write-{r['i']}" for r in rows if r['kind']=='write' and r['thread']==t}
            assert len(posts)==len(expected)+1 and len({p['id'] for p in posts})==len(posts)
            assert {p['id']: p['body'] for p in posts[1:]} == expected
        # Immediately revoke visibility after reads; both public routes and search must respect ACL.
        acl = c.call('GET', f'/forum/boards/{board}/permissions', token=token)
        c.call('PUT', f'/forum/boards/{board}/permissions', body={'visibility':'private','rules':[],'revision':acl['revision']}, token=token)
        for t in threads:
            c.call('GET', f'/forum/threads/{t}', 404)
        assert not c.call('GET', '/forum/search?q=loadmarker')
        summary['confirmed_writes'] = sum(r['kind']=='write' for r in rows)
        summary['acl_revocation_verified'] = True
        (report/'load.json').write_text(json.dumps(summary, indent=2))
        print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
