#!/usr/bin/env python3
"""Fail-closed verification of three physical PostgreSQL load/soak qualification reports.

Does not generate synthetic evidence. Never substitutes for completed real Docker runs.
Requires existing signed source/dist artifacts and an independently trusted public key.
"""
import argparse
import hashlib
import json
import math
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
PROFILES = {'10k': (10000, 300), '100k': (100000, 1800), '1m-soak': (1000000, 86400)}
ROUTES = ('ssr', 'api', 'search', 'acp', 'corpus_ssr', 'corpus_search')
CHECKS = ('identity_and_fixture', 'corpus_seeded', 'concurrent_publications', 'load_thresholds',
          'cpu_ram_io_postgres_telemetry', 'memory_budget', 'queue_delivery_latency',
          'confirmed_writes_preserved', 'acl_revocation', 'cache_warmed', 'cache_invalidation', 'ecosystem_integrity')
CHAOS_CHECKS = ('chaos_identity', 'php_fpm_restart', 'network_partition', 'temp_disk_full', 'interrupted_transaction_rollback', 'worker_crash_recovered', 'durable_queue_replay', 'postgres_recovered', 'redis_recovered')


def require(ok, description):
    if not ok:
        raise RuntimeError(description)


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def number(value):
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


def validate_report(path, name, commit, artifact_sha, version):
    doc = json.loads(Path(path).read_text())
    prefix = name + ': '
    require(doc.get('schema') == 2 and doc.get('profile') == name and doc.get('ok') is True, prefix+'incomplete run')
    require(doc.get('source_commit') == commit and doc.get('artifact_sha256') == artifact_sha, prefix+'release identity mismatch')
    require(doc.get('edition_version') == version and re.fullmatch(r'(?:sha256:)?[a-f0-9]{12,64}', doc.get('app_image', '')), prefix+'image/version identity')
    require(all(doc.get('checks', {}).get(check) is True for check in CHECKS), prefix+'missing successful mandatory check')
    if tuple(int(part) for part in version.split('.')) >= (0, 9, 5) and name == '1m-soak':
        require(all(doc.get('checks', {}).get(check) is True for check in CHAOS_CHECKS), prefix+'missing real chaos recovery evidence')
    posts, seconds = PROFILES[name]
    require(doc.get('seed_posts') == posts and doc.get('thresholds', {}).get('seconds', 0) >= seconds, prefix+'wrong corpus/duration')
    load = doc.get('load', {})
    require(number(load.get('elapsed_seconds')) and load['elapsed_seconds'] >= seconds, prefix+'short load')
    require(number(doc.get('finished_at')) and number(doc.get('started_at')) and doc['finished_at']-doc['started_at'] >= seconds, prefix+'short wall duration')
    require(number(load.get('requests_per_second')) and load['requests_per_second'] >= doc['thresholds']['minimum_rps'], prefix+'insufficient RPS')
    for route in ROUTES:
        stats = load.get('operations', {}).get(route, {})
        require(type(stats.get('requests')) is int and stats['requests'] >= 1, prefix+'missing route '+route)
        require(number(stats.get('errors')) and stats['errors'] / stats['requests'] <= doc['thresholds']['max_error_rate'], prefix+'error budget '+route)
        for p in ('p50_upper_ms', 'p95_upper_ms', 'p99_upper_ms'):
            require(number(stats.get(p)), prefix+'missing histogram '+route+'/'+p)
        require(stats['p95_upper_ms'] <= doc['thresholds']['max_p95_ms'], prefix+'slow route '+route)
    for key in ('publication', 'queue_delivery'):
        row=doc.get(key, {})
        require(number(row.get('p50_upper_ms')) and number(row.get('p95_upper_ms')) and number(row.get('p99_upper_ms')) and row.get('requests', 0)>=8 and row.get('errors')==0, prefix+'missing measured '+key)
    require(doc['queue_delivery']['p95_upper_ms'] <= doc['thresholds']['max_queue_p95_ms'], prefix+'queue latency breach')
    require(doc['publication']['p95_upper_ms'] <= doc['thresholds']['max_p95_ms'], prefix+'publication latency breach')
    require(doc.get('confirmed_writes', 0) >= 8, prefix+'no confirmed writes')
    resources = doc.get('resources', {})
    services = resources.get('services', {})
    for service in ('app', 'worker', 'scheduler', 'postgres', 'redis'):
        row = services.get(service, {})
        require(number(row.get('cpu_usage_seconds')) and row['cpu_usage_seconds'] >= 0, prefix+'no CPU '+service)
        require(number(row.get('memory_peak_bytes')) and row['memory_peak_bytes']>0, prefix+'no RAM '+service)
        require(number(row.get('memory_growth_bytes')) or (type(row.get('memory_growth_bytes')) is int and row['memory_growth_bytes']<0), prefix+'invalid memory '+service)
        require(row['memory_growth_bytes'] <= doc['thresholds']['max_memory_growth_mib']*1048576, prefix+'memory '+service)
        for counter in ('rbytes_delta','wbytes_delta','rios_delta','wios_delta'):
            require(number(row.get('io',{}).get(counter)), prefix+'no disk IO '+service)
    pg=resources.get('postgres', {})
    for item in ('xact_commit_delta','xact_rollback_delta','blks_read_delta','blks_hit_delta','temp_bytes_delta','deadlocks_delta','wal_bytes_delta','database_bytes_end','peak_lock_waiters','max_durable_queue_age_seconds','max_notification_age_seconds'):
        require(number(pg.get(item)), prefix+'missing PostgreSQL telemetry '+item)
    observations=doc.get('resource_observations',[])
    interval=doc.get('resource_samples_interval_seconds')
    require(type(interval) is int and 5<=interval<=60, prefix+'invalid sampling interval')
    require(len(observations) >= max(2, math.floor(seconds / interval)-1), prefix+'insufficient telemetry coverage')
    require(observations[0]['elapsed_seconds']>=0 and observations[-1]['elapsed_seconds']>=seconds, prefix+'telemetry not continuous')
    require(all(0 <= b['elapsed_seconds']-a['elapsed_seconds']<=interval*3+15 for a,b in zip(observations,observations[1:])), prefix+'telemetry gap')
    for item in ('host_sha256','architecture','kernel'):
        require(isinstance(doc.get('hardware',{}).get(item),str) and bool(doc['hardware'][item]), prefix+'missing hardware '+item)
    return doc


def seal(args):
    release_dir=args.release_dir.resolve()
    public=args.trusted_public_key.resolve()
    # The existing verifier checks Ed25519 signatures, manifests, checksums and
    # sidecar completeness. A caller-controlled public key is not a trust anchor:
    # this must be provisioned by the independent reviewer.
    subprocess.run(['php',str(ROOT/'tools/verify-signed-release.php'),str(release_dir),str(public)],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    artifact=release_dir/f'cajeerengine-{args.version}-dist.zip'
    require(artifact.is_file(), 'missing signed dist')
    artifact_sha=sha(artifact)
    commit=subprocess.run(['git','rev-parse','HEAD'],cwd=ROOT,check=True,capture_output=True,text=True).stdout.strip()
    require(bool(re.fullmatch(r'[0-9a-f]{40}',commit)),'missing Git commit')
    docs={}
    for name, path in zip(PROFILES,(args.small,args.medium,args.soak)):
        docs[name]=validate_report(path,name,commit,artifact_sha,args.version)
    require(len({row['hardware']['host_sha256'] for row in docs.values()})==1,'different test hosts')
    require(len({row['app_image'] for row in docs.values()})==1,'different running release images')
    report={'schema':1,'ok':True,'edition_version':args.version,'commit':commit,'dist_sha256':artifact_sha,
            'profiles':{name:{'report_sha256':sha(path),'requests':doc['load']['requests'],
                       'elapsed_seconds':doc['load']['elapsed_seconds'],
                       'requests_per_second':doc['load']['requests_per_second'],
                       'queue_p95_upper_ms':doc['queue_delivery']['p95_upper_ms'],
                       'postgres':doc['resources']['postgres']} for (name,path),doc in zip(zip(PROFILES,(args.small,args.medium,args.soak)),docs.values())}}
    require(not args.output.is_symlink(), 'output must not be a symlink')
    args.output.parent.mkdir(parents=True,exist_ok=True)
    from tempfile import NamedTemporaryFile
    import os
    with NamedTemporaryFile('w', dir=args.output.parent, delete=False) as stream:
        tmp=Path(stream.name)
        try:
            os.chmod(tmp,0o600)
            json.dump(report,stream,indent=2);stream.write('\n');stream.flush();os.fsync(stream.fileno())
        except BaseException:
            tmp.unlink(missing_ok=True);raise
    os.replace(tmp,args.output)
    print('PASS: verified real qualification reports bound to signed dist and commit',flush=True)


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--release-dir',type=Path,required=True)
    p.add_argument('--trusted-public-key',type=Path,required=True)
    p.add_argument('--small',type=Path,required=True)
    p.add_argument('--medium',type=Path,required=True)
    p.add_argument('--soak',type=Path,required=True)
    p.add_argument('--version',default='0.9.8')
    p.add_argument('--output',type=Path,required=True)
    a=p.parse_args()
    try: seal(a)
    except (OSError, ValueError, RuntimeError, KeyError, TypeError, subprocess.CalledProcessError) as e:
        print('FAIL qualification: '+type(e).__name__+': '+str(e),file=sys.stderr);sys.exit(1)

if __name__=='__main__':main()
