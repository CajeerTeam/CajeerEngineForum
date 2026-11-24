#!/usr/bin/env python3
"""Negative and positive unit fixtures for the real candidate admission code.

These fixtures are not physical evidence and are never written to release artifacts.
"""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from certify_load import validate_report, validate_thresholds, CHECKS, CHAOS_CHECKS, ROUTES, LIMITS, PROFILES
from qualify import load
from qualification_telemetry import SERVICES

spec=importlib.util.spec_from_file_location('gate',Path(__file__).resolve().parents[2]/'tools/release-candidate-gate.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
COMMIT='a'*40; DIGEST='b'*64; VERSION='1.4.11'; REPO='CajeerTeam/CajeerEngineForum'


def fixture(profile='10k'):
    posts, seconds = PROFILES[profile]
    clients, target, minimum = LIMITS[profile]
    # Equal route distribution and exact recomputed RPS.
    count=seconds*minimum//len(ROUTES)
    row={'requests':count,'errors':0,'error_types':{},'p50_upper_ms':5,'p95_upper_ms':50,'p99_upper_ms':100}
    writes=8+seconds//60-1
    write=dict(row,requests=writes)
    result={'schema':2,'profile':profile,'source_commit':COMMIT,'artifact_sha256':DIGEST,'edition_version':VERSION,
            'queue_driver':'pgsql','app_image':'sha256:'+'c'*64,'run_id':'0'*8+'-0000-0000-0000-'+'0'*12,
            'ok':True,'checks':dict.fromkeys((*CHECKS,'mixed_platform_probes'),True),'seed_posts':posts,
            'thresholds':{'clients':clients,'target_rps':target,'seconds':seconds,'minimum_rps':minimum,
                          'max_error_rate':.001,'max_p95_ms':500,'max_memory_growth_mib':512,'max_queue_p95_ms':30000},
            'started_at':100,'finished_at':seconds+200,'load':{'elapsed_seconds':seconds,'requests_per_second':count*len(ROUTES)/seconds,'requests':count*len(ROUTES),'operations':{k:dict(row) for k in ROUTES}},
            'publication':dict(write),'queue_delivery':dict(write),'confirmed_writes':writes,
            'platform_probes':{name:dict(row,requests=seconds//60-1) for name in ('ai_status','support','analytics','integrations','workflows','operations')},
            'resources':{'services':{s:{'cpu_usage_seconds':1,'memory_peak_bytes':1048576,'memory_growth_bytes':0,
                'io':{key+'_delta':0 for key in ('rbytes','wbytes','rios','wios')}} for s in SERVICES},
                'postgres':{key:0 for key in ('xact_commit_delta','xact_rollback_delta','blks_read_delta','blks_hit_delta','temp_bytes_delta','deadlocks_delta','wal_bytes_delta','database_bytes_end','peak_lock_waiters','max_durable_queue_age_seconds','max_notification_age_seconds')}},
            'resource_samples_interval_seconds':30,'resource_observations':[{'elapsed_seconds':i*30} for i in range(seconds//30+1)],
            'hardware':{'host_sha256':'e'*64,'architecture':'x86_64','kernel':'test'}}
    if profile=='1m-soak':
        result['checks'].update(dict.fromkeys((*CHAOS_CHECKS,'post_fault_workload'),True))
        result['post_fault_load']={'elapsed_seconds':60,'requests_per_second':40,'requests':2400,'operations':{k:dict(row,requests=400) for k in ROUTES}}
        result['fault_recovery_seconds']=dict.fromkeys(('postgres','redis','worker','php_fpm_restart','network_partition','temp_disk_full','interrupted_transaction_rollback'),3)
    return result


class CandidateTests(unittest.TestCase):
    def validate(self, doc):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'report.json';path.write_text(json.dumps(doc))
            return validate_report(path,doc['profile'],COMMIT,DIGEST,VERSION)

    def test_strict_profile_and_all_faults(self):
        for name in PROFILES:
            with self.subTest(name=name): self.assertEqual(self.validate(fixture(name))['profile'],name)

    def test_weakened_limits_refused(self):
        for key,value in (('clients',1),('minimum_rps',1),('target_rps',1),('max_p95_ms',1000),('max_error_rate',.01),('max_memory_growth_mib',8192),('max_queue_p95_ms',60000)):
            doc=fixture();doc['thresholds'][key]=value
            with self.subTest(key=key), self.assertRaisesRegex(RuntimeError,'weakened'): self.validate(doc)

    def test_missing_sustained_mixed_load_and_delivery(self):
        for key in ('ai_status','analytics','operations'):
            doc=fixture();doc['platform_probes'][key]['requests']=1
            with self.subTest(key=key),self.assertRaisesRegex(RuntimeError,'platform probe'):self.validate(doc)
        doc=fixture();doc['queue_delivery']['requests']=8
        with self.assertRaisesRegex(RuntimeError,'sustained writes'):self.validate(doc)

    def test_semantics_and_throughput_refused(self):
        doc=fixture();doc['load']['requests']+=1000
        with self.assertRaisesRegex(RuntimeError,'throughput'):self.validate(doc)
        doc=fixture();doc['load']['operations']['api']['error_types']={'semantic_mismatch':1};doc['load']['operations']['api']['errors']=1
        with self.assertRaisesRegex(RuntimeError,'semantic'):self.validate(doc)
        doc=fixture();doc['load']['operations']['api']['requests']-=10
        with self.assertRaisesRegex(RuntimeError,'underrepresented'):self.validate(doc)

    def test_post_fault_work_and_rto(self):
        doc=fixture('1m-soak');doc['post_fault_load']['elapsed_seconds']=59
        with self.assertRaisesRegex(RuntimeError,'recovery workload'):self.validate(doc)
        doc=fixture('1m-soak');doc['fault_recovery_seconds']['redis']=601
        with self.assertRaisesRegex(RuntimeError,'recovery time budget'):self.validate(doc)
        doc=fixture('1m-soak');doc['checks']['worker_crash_recovered']=False
        with self.assertRaisesRegex(RuntimeError,'chaos recovery'):self.validate(doc)

    def test_balanced_routes_with_multiple_corpus_variants(self):
        class Client:
            def request(self,path,headers): return 200,{},path.encode()
        routes=[('api','api',{},lambda b:b==b'api')]+[('corpus',str(i),{},lambda b:b.isdigit()) for i in range(16)]
        result=load(Client(),routes,4,1,100)
        counts=[row['requests'] for row in result['operations'].values()]
        self.assertGreater(sum(counts),50);self.assertLessEqual(abs(counts[0]-counts[1]),1)
        self.assertEqual(sum(row['errors'] for row in result['operations'].values()),0)

    def test_run_identity_and_recovery_receipts(self):
        rows={name:{'id':i+1,'run_attempt':1,'path':'.github/workflows/'+workflow,'event':event,'head_sha':COMMIT,'head_branch':'main','repository':{'full_name':REPO},'head_repository':{'full_name':REPO},'status':'completed','conclusion':'success'} for i,(name,(workflow,event)) in enumerate(gate.WORKFLOWS.items())}
        gate.validate_runs(rows,REPO,COMMIT)
        for key,value in (('head_sha','c'*40),('conclusion','failure'),('path','.github/workflows/other.yml'),('event','pull_request'),('id',rows['ci']['id'])):
            bad=copy.deepcopy(rows);bad['physical'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate_runs(bad,REPO,COMMIT)
        for checkpoint,state,version in (('drained','rolled_back','1.4.10'),('activating','committed',VERSION)):
            row={'schema':1,'ok':True,'actual_operator_sigkill':True,'checkpoint':checkpoint,'commit':COMMIT,'source_version':'1.4.10','target_version':VERSION,'target_sha256':DIGEST,'source_sha256':'c'*64,'recovery_state':state,'recovery_seconds':12,'preserved_fixture':True,'recovered_version':version}
            gate.validate_recovery(row,checkpoint,COMMIT,VERSION,DIGEST)
            for key,value in (('actual_operator_sigkill',False),('preserved_fixture',False),('recovery_state','unknown'),('target_sha256','d'*64),('recovery_seconds',901)):
                with self.subTest(checkpoint=checkpoint,key=key),self.assertRaises(ValueError):gate.validate_recovery(dict(row,**{key:value}),checkpoint,COMMIT,VERSION,DIGEST)

if __name__=='__main__': unittest.main(verbosity=2)
