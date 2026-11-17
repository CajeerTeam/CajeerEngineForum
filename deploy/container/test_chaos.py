#!/usr/bin/env python3
"""0.9.5 negative tests: real gate, fault cleanup, identity and SIGKILL journal."""
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
import chaos
import chaos_update
import update


class FakeCompose:
    prefix=['docker','compose','--project-name','forum-chaos','-f','compose.yaml']
    def __init__(self): self.actions=[]; self.free=524288; self.fail=False
    def run(self,*args,**kw):
        self.actions.append(args)
        if args==('ps','-q','app'): return 'a'*64
        if args[:5]==('exec','-T','app','stat','-f'): return 'tmpfs'
        if args[:5]==('exec','-T','app','df','-Pk'): return f'Filesystem 1K-blocks Used Available Use% Mounted on\ntmpfs 524288 0 {self.free} 0% /tmp'
        if args[:4]==('exec','-T','app','dd'): self.free=2048;return ''
        if args[:4]==('exec','-T','app','rm'): self.free=524288;return ''
        return ''

class FakeClient:
    def __init__(self,compose): self.compose=compose; self.down=False
    def request(self,path,**kw):
        if self.down or self.compose.free<16000:
            return 503,{},b''
        return 200,{},b''

class Tests(unittest.TestCase):
    def test_runtime_gateway_fails_closed_on_disk(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'storage/app').mkdir(parents=True)
            (root/'storage/app/installed.lock').touch()
            # The real PHP gate is executed, not merely searched for in source.
            script=("require "+json.dumps(str(HERE/'gate.php'))+';'
                    '$root='+json.dumps(str(root))+';'
                    '$space=static fn($path)=>str_ends_with($path,"/storage")?134217728:33554432;'
                    'if(forumContainerGate($root,$space,"/tmp")!==204)exit(1);'
                    '$low=static fn($path)=>str_ends_with($path,"/storage")?134217728:1048576;'
                    'if(forumContainerGate($root,$low,"/tmp")!==403)exit(2);'
                    '$low=static fn($path)=>str_ends_with($path,"/storage")?1048576:33554432;'
                    'if(forumContainerGate($root,$low,"/tmp")!==403)exit(3);'
                    'echo "gate: enforced\\n";')
            result=subprocess.run(['php','-r',script],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)

    def test_network_project_guard_and_untrusted_container(self):
        dc=FakeCompose()
        with self.assertRaisesRegex(RuntimeError,'disposable project'):
            chaos.backend_identity(dc,'production')
        with patch.object(chaos,'docker',return_value=json.dumps([{'Config':{'Labels':{'com.docker.compose.project':'elsewhere','com.docker.compose.service':'app'}}}])):
            with self.assertRaisesRegex(RuntimeError,'does not belong'):
                chaos.backend_identity(dc,'forum-chaos')
        def inspect(*args,**kw):
            if args[0]=='inspect':
                return json.dumps([{'Config':{'Labels':{'com.docker.compose.project':'forum-chaos','com.docker.compose.service':'app'}},'NetworkSettings':{'Networks':{'net-1':{}}}}])
            return json.dumps([{'Internal':True,'Labels':{'com.docker.compose.project':'forum-chaos','com.docker.compose.network':'backend'}}])
        with patch.object(chaos,'docker',side_effect=inspect):
            self.assertEqual(chaos.backend_identity(dc,'forum-chaos'),('a'*64,'net-1'))

    def test_bounded_tmpfs_cleanup_even_if_assertion_fails(self):
        dc=FakeCompose();client=FakeClient(dc)
        chaos.temporary_disk_full(dc,client,'/posts',{},'rejected',lambda _: None)
        self.assertEqual(dc.free,524288)
        self.assertTrue(any(x[:4]==('exec','-T','app','dd') for x in dc.actions))
        self.assertTrue(any(x[:4]==('exec','-T','app','rm') for x in dc.actions))
        with self.assertRaisesRegex(RuntimeError,'disk failure'):
            chaos.temporary_disk_full(dc,client,'/posts',{},'rejected',lambda _: (_ for _ in ()).throw(RuntimeError('disk failure')))
        self.assertEqual(dc.free,524288)

    def test_network_cleanup_after_failed_assertion(self):
        dc=FakeCompose();client=FakeClient(dc)
        calls=[]
        def fake_docker(*args,**kw):
            calls.append(args)
            return ''
        with patch.object(chaos,'backend_identity',return_value=('a'*64,'net-1')),patch.object(chaos,'docker',side_effect=fake_docker),patch.object(chaos,'fail_closed',side_effect=RuntimeError('negative assertion')):
            with self.assertRaisesRegex(RuntimeError,'negative assertion'):
                chaos.network_partition(dc,'forum-chaos',client,'/posts',{},'x',lambda:None,lambda _:None)
        self.assertTrue(any(x[1:3]==('disconnect','--force') for x in calls))
        self.assertTrue(any(x[1]=='connect' for x in calls))
        self.assertIn(('up','-d','--no-deps','--force-recreate','app'),dc.actions)

    def test_no_real_upgrade_without_private_disposable_two_release_fixture(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d);os.chmod(path,0o700)
            for version,name in [('0.9.8','old'),('0.9.9','new')]:
                (path/(name+'.env')).write_text('CE_RELEASE_VERSION='+version+'\nCE_RELEASE_SHA256='+'a'*64+'\nCE_DOMAIN=forum.test\nCE_ADMIN_EMAIL=admin@example.invalid\nCE_NAMESPACE=chaos\nCE_SECRETS_DIR=/secret\n')
            (path/'ca.pem').touch()
            from types import SimpleNamespace
            args=SimpleNamespace(project='forum-chaos',disposable='destroy-test-services',state_dir=path,old_env=path/'old.env',new_env=path/'new.env',ca=path/'ca.pem')
            self.assertEqual(chaos_update.verify_fixture(args)[1]['CE_RELEASE_VERSION'],'0.9.9')
            (path/'update.json').write_text('{}')
            with self.assertRaisesRegex(RuntimeError,'Fresh journal'):
                chaos_update.verify_fixture(args)
            args.project='production';(path/'update.json').unlink()
            with self.assertRaisesRegex(RuntimeError,'chaos component'):
                chaos_update.verify_fixture(args)

    def test_actual_sigkill_preserves_fsynced_update_journal(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'update.json'
            child=('import os,signal,sys;from pathlib import Path;'
                   'sys.path.insert(0,sys.argv[2]);import update;'
                   'update.durable_json(Path(sys.argv[1]), {"state":"activating","identity":"child-test"});'
                   'os.kill(os.getpid(),signal.SIGKILL)')
            result=subprocess.run([sys.executable,'-c',child,str(path),str(HERE)],capture_output=True)
            self.assertEqual(result.returncode,-signal.SIGKILL)
            self.assertEqual(json.loads(path.read_text())['state'],'activating')

if __name__=='__main__':unittest.main(verbosity=2)
