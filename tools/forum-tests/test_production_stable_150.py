#!/usr/bin/env python3
"""Exercise release gates and actual staging orchestration without external credentials."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import sys
import zipfile

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'deploy/container'))
from test_rc_1411 import fixture, COMMIT, DIGEST, REPO
from certify_load import validate_report
import release_identity


def load(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tools'/(name+'.py'))
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


gate=load('release-candidate-gate');candidate=load('qualification-run');publish=load('publish-qualified-release')
VERSION='1.5.0';TAG='v'+VERSION


def rows():
    return {name:{'id':i+1,'run_attempt':2,'path':'.github/workflows/'+workflow,'event':event,'head_sha':COMMIT,'head_branch':'main','repository':{'full_name':REPO},'head_repository':{'full_name':REPO},'status':'completed','conclusion':'success'} for i,(name,(workflow,event)) in enumerate(gate.WORKFLOWS.items())}


class ProductionStable(unittest.TestCase):
    def test_minor_predecessor_and_recovery(self):
        self.assertEqual(gate.previous_version(VERSION),'1.4.11')
        with self.assertRaises(ValueError):gate.previous_version('1.6.0')
        row={'schema':1,'ok':True,'actual_operator_sigkill':True,'checkpoint':'drained','commit':COMMIT,'source_version':'1.4.11','target_version':VERSION,'target_sha256':DIGEST,'source_sha256':'c'*64,'recovery_state':'rolled_back','recovery_seconds':3,'preserved_fixture':True,'recovered_version':'1.4.11','signed_deployment_verified':True,'deployment_identity':{'image':'sha256:'+'c'*64,'manifest_sha256':'d'*64}}
        gate.validate_recovery(row,'drained',COMMIT,VERSION,DIGEST)
        for change in ({'source_version':'1.5.-1'},{'signed_deployment_verified':False},{'recovered_version':'1.5.0'}):
            with self.assertRaises(ValueError):gate.validate_recovery(dict(row,**change),'drained',COMMIT,VERSION,DIGEST)

    def test_patch_keeps_signed_recovery_gate(self):
        version='1.5.2'
        self.assertEqual(gate.previous_version(version),'1.5.1')
        row={'schema':1,'ok':True,'actual_operator_sigkill':True,'checkpoint':'drained','commit':COMMIT,'source_version':'1.5.1','target_version':version,'target_sha256':DIGEST,'source_sha256':'c'*64,'recovery_state':'rolled_back','recovery_seconds':3,'preserved_fixture':True,'recovered_version':'1.5.1','signed_deployment_verified':True,'deployment_identity':{'image':'sha256:'+'c'*64,'manifest_sha256':'d'*64}}
        gate.validate_recovery(row,'drained',COMMIT,version,DIGEST)
        with self.assertRaises(ValueError):gate.validate_recovery(dict(row,signed_deployment_verified=False),'drained',COMMIT,version,DIGEST)
        doc=fixture();doc['edition_version']=version
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'report.json';p.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'deployment identity'):validate_report(p,'10k',COMMIT,DIGEST,version)

    def test_candidate_must_match_successful_attempt(self):
        run=rows()['ci'];jobs={'run_id':run['id'],'attempt':2,'commit':COMMIT,'repository':REPO}
        candidate.validate_candidate(run,jobs,REPO,COMMIT,run['id'])
        for change in ({'attempt':1},{'run_id':123},{'commit':'f'*40}):
            with self.assertRaises(ValueError):candidate.validate_candidate(run,dict(jobs,**change),REPO,COMMIT,run['id'])
        with self.assertRaises(ValueError):candidate.validate_candidate(dict(run,conclusion='failure'),jobs,REPO,COMMIT,run['id'])

    def test_receipt_rejects_stale_attempt_and_changed_report(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);r=rows();(root/'recovery.json').write_text('actual fixture')
            receipt={'schema':1,'kind':'drained','commit':COMMIT,'repository':REPO,'version':VERSION,'run_id':r['drained']['id'],'attempt':2,'ci_run_id':r['ci']['id'],'ci_attempt':2,'dist_sha256':DIGEST,'reports':{'recovery.json':candidate.digest(root/'recovery.json')}}
            path=root/'qualification-receipt.json';path.write_text(json.dumps(receipt))
            gate.validate_receipt(root,'drained',r,REPO,COMMIT,VERSION,DIGEST)
            for change in ({'attempt':1},{'ci_attempt':1},{'dist_sha256':'c'*64}):
                path.write_text(json.dumps(dict(receipt,**change)))
                with self.assertRaises(ValueError):gate.validate_receipt(root,'drained',r,REPO,COMMIT,VERSION,DIGEST)
            path.write_text(json.dumps(receipt));(root/'recovery.json').write_text('changed')
            with self.assertRaises(ValueError):gate.validate_receipt(root,'drained',r,REPO,COMMIT,VERSION,DIGEST)

    def test_physical_report_requires_deployment_identity(self):
        doc=fixture();doc['edition_version']=VERSION
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'report.json';p.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'deployment identity'):validate_report(p,'10k',COMMIT,DIGEST,VERSION)
            doc['checks']['signed_deployment_identity']=True;doc['deployment_identity']={'image':doc['app_image'],'manifest_sha256':'f'*64};p.write_text(json.dumps(doc))
            validate_report(p,'10k',COMMIT,DIGEST,VERSION)
            doc['deployment_identity']['image']='sha256:'+'f'*64;p.write_text(json.dumps(doc))
            with self.assertRaisesRegex(RuntimeError,'deployment identity'):validate_report(p,'10k',COMMIT,DIGEST,VERSION)

    def test_remote_assets_exact_and_missing_only(self):
        with tempfile.TemporaryDirectory() as d:
            a,b=Path(d)/'a.zip',Path(d)/'b.sig';a.write_bytes(b'archive');b.write_bytes(b'signature')
            expected=publish.asset_inventory([a,b]);remote=[{'name':a.name,'state':'uploaded','size':a.stat().st_size,'digest':expected[a.name]['digest']}]
            self.assertEqual(publish.missing_assets(remote,expected),[b])
            with self.assertRaises(ValueError):publish.missing_assets(remote,expected,complete=True)
            for bad in ([dict(remote[0],digest='wrong')],[dict(remote[0],state='starter')],remote*2,[dict(remote[0],name='extra')]):
                with self.assertRaises(ValueError):publish.missing_assets(bad,expected)
            with self.assertRaises(ValueError):publish.asset_inventory([a,a])

    def test_resume_draft_upload_only_missing_never_publishes(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);a,b,notes=(root/x for x in ('a.zip','b.sig','notes'));a.write_bytes(b'archive');b.write_bytes(b'signature');notes.write_text('release notes')
            expected=publish.asset_inventory([a,b]);remote=[{'name':a.name,'state':'uploaded','size':expected[a.name]['size'],'digest':expected[a.name]['digest']}];calls=[]
            def get(*args):return {'assets':copy.deepcopy(remote)}
            def api(repo,path,missing=False):
                return {'object':{'sha':COMMIT},'sha':COMMIT}
            def gh(*args,**kw):
                calls.append(args);self.assertEqual(args[:2],('release','upload'));name=Path(args[3]).name;remote.append({'name':name,'state':'uploaded','size':expected[name]['size'],'digest':expected[name]['digest']})
            with patch.object(publish,'get_draft',get),patch.object(publish,'api',api),patch.object(publish,'gh',gh):
                publish.stage(REPO,TAG,COMMIT,notes,[a,b]);publish.stage(REPO,TAG,COMMIT,notes,[a,b])
            self.assertEqual(len(calls),1);self.assertEqual(Path(calls[0][3]),b)
            with patch.object(publish,'get_draft',get),patch.object(publish,'api',lambda *a,**kw:{'object':{'sha':'f'*40}}),patch.object(publish,'gh',gh):
                with self.assertRaises(ValueError):publish.stage(REPO,TAG,COMMIT,notes,[a,b])
            self.assertEqual(len(calls),1)

    def test_live_manifest_and_background_image_verification(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);a=root/'release.zip';key=root/'release.pub';key.write_text('fixture');manifest={'edition':'forum','version':VERSION,'checksums':{'index':'a'*64}}
            with zipfile.ZipFile(a,'w') as z:z.writestr('cajeerengine/release-manifest.json',json.dumps(manifest))
            site={'CE_RELEASE_VERSION':VERSION,'CE_RELEASE_SHA256':DIGEST};tag=f'cajeerengine-forum:{VERSION}-{DIGEST}'
            class DC:
                env={};bad=False;checks=[]
                def run(self,*args,**kw):
                    if args[:2]==('ps','-q'):return {'app':'a','worker':'b','scheduler':'c'}[args[2]]*64
                    if args[3]=='cat':return json.dumps({'wrong':True} if self.bad and args[2]=='worker' else manifest)
                    self.checks.append(args[2]);return 'verified'
            dc=DC()
            def inspect(*args,**kw):return json.dumps([{'State':{'Running':True},'Config':{'Image':tag},'Image':'sha256:'+'e'*64}])
            with patch.object(release_identity.subprocess,'run'),patch.object(release_identity.subprocess,'check_output',inspect):
                result=release_identity.verify_running(dc,site,a,key);self.assertEqual(set(dc.checks),{'app','worker','scheduler'});self.assertEqual(result['image'],'sha256:'+'e'*64)
                dc.bad=True
                with self.assertRaisesRegex(RuntimeError,'Installed manifest'):release_identity.verify_running(dc,site,a,key)
            # subprocess is intentionally mocked only in this unit test; live workflows run real PHP/Docker.


if __name__=='__main__':unittest.main(verbosity=2)
