#!/usr/bin/env python3
"""Negative tests for the production admission validator; not live qualification."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('gate',ROOT/'tools/release-candidate-gate.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
COMMIT='a'*40; DIGEST='b'*64; REPO='CajeerTeam/CajeerEngineForum'


def fixture():
    op={'requests':300,'errors':0,'p95_upper_ms':100,'error_types':{}}
    load={'elapsed_seconds':60,'requests':600,'requests_per_second':10,'operations':{'thread':copy.deepcopy(op),'page':copy.deepcopy(op)}}
    return {'schema':1,'ok':True,'commit':COMMIT,'candidate_sha256':DIGEST,'source_version':'1.5.9','target_version':'1.5.10',
            'github_dist_sha256':'c'*64,'key_id':'d'*64,'transaction_id':'20261124-050000-'+'e'*16,
            **dict.fromkeys(('actual_autonomous_update','canonical_github','backup_rehearsed','installed_integrity','preserved_fixture','no_duplicate_apply'),True),
            'manual_requests':0,'seconds':30,'during':{'ok':80,'maintenance':40,'errors':0},'before':copy.deepcopy(load),'after':copy.deepcopy(load)}


class Qualification(unittest.TestCase):
    def validate(self,row): gate.validate_autonomous(row,COMMIT,DIGEST)
    def test_valid(self): self.validate(fixture())
    def test_identity(self):
        for field,value in [('commit','f'*40),('candidate_sha256','c'*64),('source_version','1.5.8'),('target_version','1.5.11'),('github_dist_sha256',DIGEST),('key_id','x'),('transaction_id','../bad')]:
            with self.subTest(field=field),self.assertRaises(ValueError):self.validate(dict(fixture(),**{field:value}))
    def test_proofs(self):
        for key in ('actual_autonomous_update','canonical_github','backup_rehearsed','installed_integrity','preserved_fixture','no_duplicate_apply'):
            with self.subTest(key=key),self.assertRaises(ValueError):self.validate(dict(fixture(),**{key:False}))
        with self.assertRaises(ValueError):self.validate(dict(fixture(),manual_requests=1))
    def test_maintenance(self):
        for row in ({'ok':10,'maintenance':0,'errors':0},{'ok':0,'maintenance':10,'errors':0},{'ok':10,'maintenance':10,'errors':1}):
            with self.assertRaises(ValueError):self.validate(dict(fixture(),during=row))
        for sec in (0,901,float('nan')):
            with self.assertRaises(ValueError):self.validate(dict(fixture(),seconds=sec))
    def test_load(self):
        for key,val in [('elapsed_seconds',59),('requests',1),('requests_per_second',100),('operations',{})]:
            row=fixture();row['before'][key]=val
            with self.subTest(key=key),self.assertRaises(ValueError):self.validate(row)
        for key,val in [('errors',1),('p95_upper_ms',501),('requests',299),('error_types',{'semantic_mismatch':1})]:
            row=fixture();row['after']['operations']['thread'][key]=val
            with self.subTest(key=key),self.assertRaises(ValueError):self.validate(row)
    def test_new_predecessor_requires_signed_identity(self):
        self.assertEqual(gate.previous_version('1.5.11'),'1.5.10')
        row={'schema':1,'ok':True,'actual_operator_sigkill':True,'checkpoint':'drained','commit':COMMIT,'source_version':'1.5.10','target_version':'1.5.11','target_sha256':DIGEST,'source_sha256':'c'*64,'recovery_state':'rolled_back','recovery_seconds':3,'preserved_fixture':True,'recovered_version':'1.5.10','signed_deployment_verified':True,'deployment_identity':{'image':'sha256:'+'c'*64,'manifest_sha256':'d'*64}}
        gate.validate_recovery(row,'drained',COMMIT,'1.5.11',DIGEST)
        with self.assertRaises(ValueError):gate.validate_recovery(dict(row,signed_deployment_verified=False),'drained',COMMIT,'1.5.11',DIGEST)
    def test_mandatory_independent_run(self):
        rows={name:{'id':i+1,'run_attempt':1,'path':'.github/workflows/'+workflow,'event':event,'head_sha':COMMIT,'head_branch':'main','repository':{'full_name':REPO},'head_repository':{'full_name':REPO},'status':'completed','conclusion':'success'} for i,(name,(workflow,event)) in enumerate(gate.WORKFLOWS.items())}
        gate.validate_runs(rows,REPO,COMMIT)
        missing=dict(rows);del missing['autonomous']
        with self.assertRaises(ValueError):gate.validate_runs(missing,REPO,COMMIT)
        rows['autonomous']['conclusion']='failure'
        with self.assertRaises(ValueError):gate.validate_runs(rows,REPO,COMMIT)
    def test_stable_pre_and_post_are_distinct(self):
        pre=dict(fixture(), source_version='1.5.10', target_version='1.5.11', phase='prepublication', baseline_sha256='f'*64, baseline_integrity=True)
        post=dict(pre, source_version='1.5.11', target_version='1.6.0', phase='postpublication', baseline_sha256=pre['github_dist_sha256'], github_dist_sha256=DIGEST, transaction_id='20261124-050001-'+'e'*16)
        gate.validate_autonomous(pre,COMMIT,DIGEST,'1.6.0')
        gate.validate_autonomous(post,COMMIT,DIGEST,'1.6.0',published=True)
        for change in ({'phase':'prepublication'},{'baseline_integrity':False},{'baseline_sha256':DIGEST},{'github_dist_sha256':'c'*64}):
            with self.assertRaises(ValueError): gate.validate_autonomous(dict(post,**change),COMMIT,DIGEST,'1.6.0',published=True)
        with self.assertRaises(ValueError): gate.validate_autonomous(pre,COMMIT,DIGEST,'1.6.0',published=True)
        import hashlib
        rows={name:{'id':i+1,'run_attempt':1,'path':'.github/workflows/'+workflow,'event':event,'head_sha':COMMIT,'head_branch':'main','repository':{'full_name':REPO},'head_repository':{'full_name':REPO},'status':'completed','conclusion':'success'} for i,(name,(workflow,event)) in enumerate(gate.WORKFLOWS.items())}
        run=dict(rows['autonomous'],id=999)
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)
            def write(row):
                f=path/'autonomous.json'; f.write_text(json.dumps(row))
                receipt={'schema':1,'kind':'postpublication','commit':COMMIT,'repository':REPO,'version':'1.6.0','run_id':999,'attempt':1,'ci_run_id':rows['ci']['id'],'ci_attempt':1,'dist_sha256':DIGEST,'reports':{'autonomous.json':hashlib.sha256(f.read_bytes()).hexdigest()}}
                (path/'qualification-receipt.json').write_text(json.dumps(receipt))
            write(post)
            gate.validate_postpublication(path,run,rows,REPO,COMMIT,'1.6.0',DIGEST,pre,post['key_id'])
            for bad in (dict(run,id=rows['autonomous']['id']),dict(run,run_attempt=2),dict(run,conclusion='failure')):
                with self.assertRaises(ValueError): gate.validate_postpublication(path,bad,rows,REPO,COMMIT,'1.6.0',DIGEST,pre,post['key_id'])
            for change in ({'baseline_sha256':'f'*64},{'transaction_id':pre['transaction_id']},{'key_id':'f'*64}):
                write(dict(post,**change))
                with self.assertRaises(ValueError): gate.validate_postpublication(path,run,rows,REPO,COMMIT,'1.6.0',DIGEST,pre,post['key_id'])
            write(post); (path/'autonomous.json').write_text('{}')
            with self.assertRaises(ValueError): gate.validate_postpublication(path,run,rows,REPO,COMMIT,'1.6.0',DIGEST,pre,post['key_id'])
    def test_receipt_binds_report(self):
        import hashlib
        with tempfile.TemporaryDirectory() as d:
            path=Path(d); f=path/'autonomous.json';f.write_text(json.dumps(fixture()));rows={'ci':{'id':1,'run_attempt':2},'autonomous':{'id':5,'run_attempt':3}}
            r={'schema':1,'kind':'autonomous','commit':COMMIT,'repository':REPO,'version':'1.5.11','run_id':5,'attempt':3,'ci_run_id':1,'ci_attempt':2,'dist_sha256':DIGEST,'reports':{'autonomous.json':hashlib.sha256(f.read_bytes()).hexdigest()}}
            (path/'qualification-receipt.json').write_text(json.dumps(r));gate.validate_receipt(path,'autonomous',rows,REPO,COMMIT,'1.5.11',DIGEST)
            f.write_text('{}')
            with self.assertRaises(ValueError):gate.validate_receipt(path,'autonomous',rows,REPO,COMMIT,'1.5.11',DIGEST)

if __name__=='__main__': unittest.main()
