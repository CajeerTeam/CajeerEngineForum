#!/usr/bin/env python3
"""0.9.4 regression: real sampler parsers, live queue measurements, fail-closed evidence."""
import json
from pathlib import Path
import sys
import threading
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from qualification_telemetry import ResourceMonitor, QueueLatencyMonitor, sample, summarize, parse_cpu, parse_io, SERVICES
from qualify import Histogram
from certify_load import validate_report, PROFILES, CHECKS, CHAOS_CHECKS, ROUTES


class FakeCompose:
    def __init__(self):
        self.counter = 0
    def run(self, *args, **kwargs):
        if args[-1]=='/sys/fs/cgroup/memory.current': return '1048576\n'
        if args[-1]=='/sys/fs/cgroup/cpu.stat': return 'usage_usec 100000\nuser_usec 70000\nsystem_usec 30000\n'
        if args[-1]=='/sys/fs/cgroup/io.stat': return '8:0 rbytes=1024 wbytes=2048 rios=10 wios=20\n8:16 rbytes=5 wbytes=7 rios=1 wios=1\n'
        raise RuntimeError('unexpected command')
    def sql(self, *args, **kwargs):
        self.counter += 1
        return json.dumps({'statistics':{'xact_commit':100+self.counter,'xact_rollback':0,'blks_read':10,'blks_hit':100,'temp_bytes':0,'deadlocks':0},
                           'lock_waiters':0,'active_sessions':1,'database_bytes':1024,'wal_bytes':'10240',
                           'durable_queue':{'pending':0,'oldest_ready_age_seconds':0},
                           'notifications':{'pending':0,'oldest_age_seconds':0}})


class QueueClient:
    def __init__(self): self.ids=set()
    def request(self, path, headers):
        assert path=='/api/v1/forum/notifications'
        return 200,{},json.dumps({'data':{'items':[{'post_id':pid} for pid in self.ids]}}).encode()


class Tests(unittest.TestCase):
    def test_cgroup_telemetry_and_pg(self):
        self.assertEqual(parse_cpu('usage_usec 120\nuser_usec 30')['usage_usec'],120)
        self.assertEqual(parse_io('8:0 rbytes=1 wbytes=4 rios=2 wios=3\n8:16 rbytes=5 wbytes=2 rios=1 wios=9')['wbytes'],6)
        for raw in ('', 'nonsense', 'cpu 1'):
            with self.assertRaises(RuntimeError): parse_cpu(raw)
        self.assertEqual(parse_io('')['wbytes'], 0) # valid read, no I/O recorded yet
        for raw in ('8:0 foo=1',):
            with self.assertRaises(RuntimeError): parse_io(raw)
        d=FakeCompose()
        first=sample(d);second=sample(d)
        self.assertEqual(set(first['services']),set(SERVICES))
        self.assertEqual(first['services']['postgres']['io']['wbytes'],2055)
        summary=summarize(first,second,[first,second])
        self.assertEqual(summary['postgres']['xact_commit_delta'],1)
        self.assertEqual(summary['services']['postgres']['memory_growth_bytes'],0)
        d.sql=lambda *args, **kwargs: '{}'
        with self.assertRaises(RuntimeError):sample(d)

    def test_background_sample(self):
        d=FakeCompose();watch=ResourceMonitor(d,interval_seconds=.05).start();time.sleep(.12)
        summary=watch.finish()
        self.assertGreaterEqual(len(watch.samples),3)
        self.assertEqual(summary['postgres']['deadlocks_delta'],0)

    def test_real_notification_probe_and_failure(self):
        client=QueueClient();watch=QueueLatencyMonitor(client,{'Authorization':'Bearer test'},Histogram(),.02).start()
        watch.track('post-1');client.ids.add('post-1')
        report=watch.finish(max_wait=1)
        self.assertEqual(report['requests'],1)
        self.assertEqual(report['errors'],0)
        missing=QueueLatencyMonitor(QueueClient(),{},Histogram(),.01).start()
        missing.track('undelivered')
        with self.assertRaises(RuntimeError):missing.finish(max_wait=.06)

    def test_certificate_strict_profile_and_binding(self):
        # This is an intentionally synthetic UNIT FIXTURE for rejecting forged or
        # incomplete reports, not certification output or physical load evidence.
        import tempfile
        commit='a'*40;artifact='b'*64;version='0.9.4';profile='10k'
        row={'requests':50,'errors':0,'p50_upper_ms':5,'p95_upper_ms':50,'p99_upper_ms':100}
        telemetry={'services':{s:{'cpu_usage_seconds':1,'memory_peak_bytes':1048576,'memory_growth_bytes':0,
                                   'io':{key+'_delta':0 for key in ('rbytes','wbytes','rios','wios')}} for s in SERVICES},
                   'postgres':{key:0 for key in ('xact_commit_delta','xact_rollback_delta','blks_read_delta','blks_hit_delta','temp_bytes_delta','deadlocks_delta','wal_bytes_delta','database_bytes_end','peak_lock_waiters','max_durable_queue_age_seconds','max_notification_age_seconds')}}
        observations=[{'elapsed_seconds':i*30} for i in range(11)]
        doc={'schema':2,'profile':profile,'source_commit':commit,'artifact_sha256':artifact,'edition_version':version,
             'app_image':'sha256:'+'c'*64,'ok':True,'checks':{k:True for k in CHECKS},'seed_posts':10000,
             'thresholds':{'seconds':300,'minimum_rps':5,'max_error_rate':.001,'max_p95_ms':500,'max_memory_growth_mib':512,'max_queue_p95_ms':30000},
             'started_at':0,'finished_at':330,'load':{'elapsed_seconds':300,'requests_per_second':10,'requests':3000,'operations':{k:row for k in ROUTES}},
             'publication':row,'queue_delivery':row,'confirmed_writes':8,'resources':telemetry,
             'resource_samples_interval_seconds':30,'resource_observations':observations,
             'hardware':{'host_sha256':'e'*64,'architecture':'x86_64','kernel':'test'}}
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'test.json'
            path.write_text(json.dumps(doc));validate_report(path,profile,commit,artifact,version)
            doc['load']['elapsed_seconds']=60;path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'short load'):validate_report(path,profile,commit,artifact,version)
            doc['load']['elapsed_seconds']=300;doc['resource_observations'][3]['elapsed_seconds']=300
            path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'telemetry gap'):validate_report(path,profile,commit,artifact,version)
            doc['resource_observations']=observations=[{'elapsed_seconds':i*30} for i in range(11)]
            doc['edition_version']='0.9.6';doc['artifact_sha256']=artifact;doc['profile']='1m-soak'
            path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'missing real chaos recovery evidence'):
                validate_report(path,'1m-soak',commit,artifact,'0.9.6')
            # Same 10k document need not contain 24h chaos, but soak must.
            doc['edition_version']='0.9.4';doc['profile']='10k'
            doc['artifact_sha256']='f'*64;path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'release identity'):validate_report(path,profile,commit,artifact,version)

if __name__=='__main__':unittest.main()
