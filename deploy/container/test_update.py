#!/usr/bin/env python3
"""Local orchestration tests; real Compose/SQL/TLS upgrade is a required CI job."""
import importlib.util
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import patch, Mock

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('container_update',HERE/'update.py')
u=importlib.util.module_from_spec(spec); spec.loader.exec_module(u)

class UpdateTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.root=Path(self.tmp.name)
        self.old=self.root/'old.env'; self.new=self.root/'new.env'
        self.old.write_text('CE_RELEASE_VERSION=0.8.2\nCE_RELEASE_SHA256='+'a'*64+'\nCE_DOMAIN=forum.test\nCE_ADMIN_EMAIL=admin@example.invalid\nCE_NAMESPACE=test\nCE_SECRETS_DIR=/secure/test\n')
        self.new.write_text(self.old.read_text().replace('0.8.2','0.8.3').replace('a'*64,'b'*64))
        self.args=SimpleNamespace(old_env=self.old,new_env=self.new,state_dir=self.root,project='test',ca=None,dry_run=False,yes=False)
    def tearDown(self): self.tmp.cleanup()
    def test_site_identity(self):
        d=u.Deployment(self.args); self.assertEqual(d.previous,'0.8.2'); self.assertEqual(d.target,'0.8.3')
        for text in [self.new.read_text()+'CE_QUEUE_DRIVER=redis\n',self.new.read_text().replace('forum.test','other.test'),self.new.read_text().replace('0.8.3','0.8.1'),self.new.read_text()+'CE_DOMAIN=duplicate.test\n']:
            with self.subTest(text=text):
                save=self.new.read_text(); self.new.write_text(text)
                with self.assertRaises(ValueError): u.Deployment(self.args)
                self.new.write_text(save)
    def test_legacy_compat_preload_is_scoped_to_operator(self):
        import os
        import shutil
        import subprocess
        php = shutil.which('php')
        if php is None:
            self.skipTest('PHP unavailable')
        compat = (HERE/'php-compat.ini').read_text()
        self.assertIn('auto_prepend_file=/opt/ce-update/ForumSearchMaintenance.php', compat)
        operator = (HERE/'update-operator.php').read_text()
        self.assertIn("putenv('PHP_INI_SCAN_DIR=:/opt/ce-update/compat')", operator)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'zz-compat.ini').write_text('auto_prepend_file=' +
                str(HERE.parents[1]/'core/Forum/Service/ForumSearchMaintenance.php')+'\n')
            (root/'probe.php').write_text('<?php echo class_exists("CajeerEngine\\Forum\\Service\\ForumSearchMaintenance",false)?"loaded":"missing";')
            baseline = subprocess.check_output([php, str(root/'probe.php')], text=True)
            env = dict(os.environ, PHP_INI_SCAN_DIR=':'+str(root))
            observed = subprocess.check_output([php, str(root/'probe.php')], env=env, text=True)
            self.assertEqual(baseline, 'missing')
            self.assertEqual(observed, 'loaded')

    def test_project_lock_across_journals(self):
        with u.project_lock("forum",self.root):
            with self.assertRaises(BlockingIOError):
                with u.project_lock("forum",self.root): pass
        with u.project_lock("forum",self.root): pass
    def test_journal_identity_and_order(self):
        d=u.Deployment(self.args); events=[]
        d.verify=lambda: events.append('verify')
        d.dc=lambda site,*args,**kw: events.append(args[0])
        d.stop=lambda site: events.append('stop')
        d.operator=lambda mode: events.append(mode)
        record=d.record
        def rec(state): events.append(state); record(state)
        d.record=rec
        d.activate=lambda site,expected,final: (events.append('activate'),d.record(final))
        d.apply()
        self.assertEqual(events,['verify','build','prepared','stop','drained','apply','applied','activating','activate','committed'])
        self.assertEqual(d.state(),'committed')
        self.new.write_text(self.new.read_text().replace('b'*64,'c'*64))
        with self.assertRaises(ValueError): u.Deployment(self.args).state()
    def test_dry_run_has_no_journal_or_writes(self):
        self.args.dry_run=True; d=u.Deployment(self.args)
        d.verify=Mock(); d.dc=Mock(); d.operator=Mock(); d.stop=Mock(); d.apply()
        d.verify.assert_called_once(); d.dc.assert_not_called(); d.operator.assert_not_called(); d.stop.assert_not_called(); self.assertIsNone(d.state())
    def test_prepared_recovery_restores_old_without_database_rollback(self):
        d=u.Deployment(self.args);d.record('prepared')
        d.stop=Mock();d.operator=Mock();d.activate=Mock()
        d.recover()
        d.stop.assert_called_once_with(self.old)
        d.operator.assert_not_called()
        d.activate.assert_called_once_with(self.old,'0.8.2','rolled_back')
        self.assertEqual(d.state(),'prepared')  # Not committed until activation passes.

    def test_committed_rollback_requires_explicit_data_loss_ack(self):
        d=u.Deployment(self.args);d.record('committed')
        d.stop=Mock();d.operator=Mock();d.activate=Mock()
        with self.assertRaisesRegex(RuntimeError,'Explicit --yes'):
            d.recover(rollback=True)
        d.stop.assert_not_called();d.operator.assert_not_called()

    def test_recover_before_activation(self):
        d=u.Deployment(self.args); d.record('drained'); d.stop=Mock(); d.operator=Mock(); d.activate=Mock()
        d.recover(); d.operator.assert_called_once_with('recover'); d.activate.assert_called_once_with(self.old,'0.8.2','rolled_back'); self.assertEqual(d.state(),'rolling_back')
    def test_resume_after_activation_and_explicit_rollback(self):
        d=u.Deployment(self.args); d.record('activating'); d.stop=Mock(); d.operator=Mock(); d.activate=Mock()
        d.recover(); d.operator.assert_not_called(); d.activate.assert_called_once_with(self.new,'0.8.3','committed')
        d.record('committed'); d.activate.reset_mock()
        with self.assertRaises(RuntimeError): d.recover(True)
        self.args.yes=True; d.recover(True); d.operator.assert_called_once_with('recover'); d.activate.assert_called_once_with(self.old,'0.8.2','rolled_back')
    def test_failed_apply_keeps_durable_checkpoint(self):
        d=u.Deployment(self.args); d.verify=Mock(); d.dc=Mock(); d.stop=Mock(); d.operator=Mock(side_effect=RuntimeError('injected failure')); d.activate=Mock()
        with self.assertRaises(RuntimeError): d.apply()
        self.assertEqual(d.state(),'drained'); d.activate.assert_not_called()
    def test_unhealthy_activation_never_commits(self):
        d=u.Deployment(self.args)
        d.record('activating')
        d.operator=Mock()
        d.dc=Mock(return_value='init-id\n')
        d.http_check=Mock()
        d.operational_gate=Mock(side_effect=RuntimeError('real operational gate failed'))
        with patch.object(u.subprocess,'run',return_value=SimpleNamespace(stdout='0')), \
             patch.object(u.subprocess,'check_output',return_value='0\n'):
            with self.assertRaisesRegex(RuntimeError,'operational gate failed'):
                d.activate(self.new,'0.8.3','committed')
        self.assertEqual(d.state(),'activating')
        d.operational_gate.assert_called_once_with(self.new)

    def test_gateway_started_last(self):
        d=u.Deployment(self.args); events=[]; d.record('activating')
        d.operator=lambda mode:events.append(('operator',mode)); d.http_check=lambda site,expected:events.append(('http',expected))
        d.operational_gate=lambda site:events.append(('operations',site))
        def dc(site,*args,**kw):
            events.append(args)
            if args[:2]==('ps','-aq'): return 'init-id\n'
        d.dc=dc
        with patch.object(u.subprocess,'run',return_value=SimpleNamespace(stdout='0')),patch.object(u.subprocess,'check_output',return_value='0\n'):
            d.activate(self.new,'0.8.3','committed')
        gateway=next(i for i,x in enumerate(events) if x[0]=='up' and x[-1]=='gateway')
        self.assertLess(next(i for i,x in enumerate(events) if x[:3]==('exec','-T','worker')),gateway)
        self.assertLess(next(i for i,x in enumerate(events) if x[:3]==('exec','-T','scheduler')),gateway)
        self.assertEqual(events[-2],('http','0.8.3')); self.assertEqual(events[-1],('operations',self.new)); self.assertEqual(d.state(),'committed')

if __name__=='__main__': unittest.main(verbosity=2)
