#!/usr/bin/env python3
"""Negative acceptance of stable 1.0.0 external physical evidence and Ed25519 GO."""
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('stable_certification',ROOT/'tools/stable-certification.py')
stable=importlib.util.module_from_spec(spec);spec.loader.exec_module(stable)
COMMIT='a'*40


def sign(path,private,public):
    code='''
    $pair=sodium_crypto_sign_keypair();
    file_put_contents($argv[1],base64_encode(sodium_crypto_sign_secretkey($pair))."\\n");
    file_put_contents($argv[2],base64_encode(sodium_crypto_sign_publickey($pair))."\\n");
    '''
    subprocess.run(['php','-r',code,str(private),str(public)],check=True)


def sign_approval(path,private,signature):
    code='''
    $secret=base64_decode(trim(file_get_contents($argv[2])),true);
    file_put_contents($argv[3],base64_encode(sodium_crypto_sign_detached(file_get_contents($argv[1]),$secret))."\\n");
    '''
    subprocess.run(['php','-r',code,str(path),str(private),str(signature)],check=True)


class StableCertificationTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.release_key=self.root/'release.pub';self.release_key.write_text('release-root')
        self.operator_private=self.root/'operator.secret';self.operator_key=self.root/'operator.pub'
        sign(self.root/'operator-generated.secret',self.operator_private,self.operator_key)
        (self.root/'ci-release.pub').write_text('release-root')
        (self.root/'cajeerengine-1.0.0-dist.zip').write_bytes(b'exact stable dist')
        (self.root/'cajeerengine-1.0.0-source.zip').write_bytes(b'exact stable source')
        self.dist=stable.digest(self.root/'cajeerengine-1.0.0-dist.zip')
        (self.root/'certification.json').write_text(json.dumps({
            'version':'1.0.0','commit':COMMIT,'signing':'trusted-release-key','run_id':123,'attempt':1}))
        self.load={}
        for name in ('10k','100k','1m-soak'):
            path=self.root/(name+'.json');path.write_text(json.dumps({'profile':name,'ok':True}))
            self.load[name]={'hardware':{'host_sha256':'same-physical-host'},'app_image':'sha256:'+('b'*64),'finished_at':1763370000,
                 'load':{'elapsed_seconds':{'10k':300,'100k':1800,'1m-soak':86400}[name],
                         'requests_per_second':40},'queue_delivery':{'p95_upper_ms':250}}
        for checkpoint,state in (('drained','rolled_back'),('activating','committed')):
            (self.root/('chaos-'+checkpoint+'.json')).write_text(json.dumps({
               'schema':1,'ok':True,'checkpoint':checkpoint,'source_version':'0.9.9',
               'target_version':'1.0.0','target_sha256':self.dist,'source_sha256':'b'*64,
               'recovery_state':state,'actual_operator_sigkill':True}))
        self.approval=self.root/'operator-approval.json'
        self.write_approval()

    def write_approval(self):
        self.approval.write_text(json.dumps({
            'schema':1,'decision':'GO','version':'1.0.0','commit':COMMIT,'dist_sha256':self.dist,
            'reviewed_at_utc':'2026-11-17T11:00:00Z','input_sha256':{
              name:stable.digest(self.root/name) for name in stable.RAW},
            'checks':{'load_and_24h_soak_reviewed':True,'chaos_recovery_reviewed':True,
                      'restore_dr_reviewed':True,'oncall_and_rollback_ready':True}}))
        sign_approval(self.approval,self.operator_private,self.root/'operator-approval.json.sig')

    def inspect(self):
        fake=SimpleNamespace(validate_report=lambda path,name,commit,digest,version:self.load[name])
        original=stable.subprocess.run
        def verified(cmd,**kwargs):
            if cmd[0]=='python3':return SimpleNamespace(returncode=0)
            return original(cmd,**kwargs)
        with patch.object(stable,'module',return_value=fake), patch.object(stable.subprocess,'run',side_effect=verified):
            return stable.inspect(self.root,self.release_key,self.operator_key)

    def test_real_operator_signature_and_all_evidence(self):
        result=self.inspect()
        self.assertEqual(result['dist_sha256'],self.dist)
        self.assertEqual(result['load']['1m-soak']['elapsed_seconds'],86400)
        self.assertEqual(result['chaos']['drained']['result'],'rolled_back')
        with patch.object(stable,'inspect',return_value=result):
            args=SimpleNamespace(directory=self.root,trusted_public_key=self.release_key,
                                 operator_public_key=self.operator_key,action='issue')
            stable.run(args)
            args.action='verify';stable.run(args)
            (self.root/'stable-certification.json').write_text('{"ok":true}')
            with self.assertRaisesRegex(ValueError,'tampered'):stable.run(args)

    def test_missing_physical_and_bad_operator_approval_fail_closed(self):
        self.inspect()
        self.approval.write_bytes(self.approval.read_bytes()+b' ')
        with self.assertRaises(subprocess.CalledProcessError):self.inspect()
        self.write_approval()
        (self.root/'chaos-activating.json').unlink()
        with self.assertRaises(ValueError):self.inspect()

    def test_changed_report_rejected_even_with_valid_operator_signature(self):
        (self.root/'100k.json').write_text('{"profile":"100k","ok":false}')
        with self.assertRaisesRegex(ValueError,'Operator has not signed'):self.inspect()
        self.write_approval()
        self.load['100k']['hardware']['host_sha256']='different-host'
        with self.assertRaisesRegex(ValueError,'same host'):self.inspect()

    def test_refuses_reused_operator_release_trust(self):
        self.release_key.write_bytes(self.operator_key.read_bytes())
        with self.assertRaisesRegex(ValueError,'differ'):self.inspect()


if __name__=='__main__':unittest.main(verbosity=2)
