#!/usr/bin/env python3
"""Actual TLS and simulated Docker subprocess regression for the operator gate."""
import json
import shutil
import time
import os
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import threading
import unittest
from types import SimpleNamespace

from operations import Operations
from update import read_site, Deployment


class OperationsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root/'site.env'
        self.port = self._free_port()
        self.site.write_text('\n'.join([
            'CE_RELEASE_VERSION=0.9.0', 'CE_RELEASE_SHA256='+'a'*64,
            'CE_DOMAIN=forum.test', 'CE_ADMIN_EMAIL=admin@example.invalid',
            'CE_NAMESPACE=forum-operations', 'CE_SECRETS_DIR=/srv/secure',
            'CE_BIND_ADDRESS=127.0.0.1', 'CE_HTTPS_PORT='+str(self.port),
            'CE_QUEUE_DRIVER=pgsql', 'CE_LOOP_TIMEOUT_SECONDS=1800',
            'CE_RATE_LIMIT_GLOBAL=120', 'CE_RATE_LIMIT_FORUM_SEARCH=60', '']))
        self.cert = self.root/'server.crt'; self.key = self.root/'server.key'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes',
                        '-days','1','-subj','/CN=forum.test',
                        '-addext','subjectAltName=DNS:forum.test',
                        '-keyout',str(self.key),'-out',str(self.cert)],
                       check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        self.rows = {}
        for name in ('postgres','redis','init','app','worker','scheduler','gateway'):
            image = ('cajeerengine-forum:0.9.0-'+'a'*64 if name in ('app','worker','scheduler')
                     else 'cajeerengine-forum-gateway:0.9.0' if name=='gateway' else 'upstream')
            self.rows[name] = {'Service':name, 'State':'exited' if name=='init' else 'running',
                               'ExitCode':0, 'Health':'' if name=='init' else 'healthy',
                               'Image':image}
        self.commands=[]

    @staticmethod
    def _free_port():
        with socket.socket() as s:
            s.bind(('127.0.0.1',0));return s.getsockname()[1]

    def run_command(self, cmd, **kwargs):
        self.commands.append(cmd)
        if 'ps' in cmd:
            return SimpleNamespace(returncode=0,stdout='\n'.join(json.dumps(v) for v in self.rows.values()))
        if 'backup:automation:status' in cmd:
            return SimpleNamespace(returncode=0,stdout=json.dumps({'ok':True,'last_offsite':int(time.time()),'pending':0,'latest_local_id':'abc','latest_offsite_id':'abc'}))
        if 'forum:ecosystem:check' in cmd:
            return SimpleNamespace(returncode=0,stdout=json.dumps({'ok':True,'deep':True,'checks':{k:True for k in ('schema','integrity','search','migration_idle','extensions','community_policy','theme','delegation')}}))
        return SimpleNamespace(returncode=0,stdout='')

    def test_real_tls_and_live_edition(self):
        import http.server
        outer=self
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*a): pass
            def do_GET(self):
                paths={
                    '/health/ready':(200,b'OK'),
                    '/api/v1/health':(200,json.dumps({'status':'ok','edition_version':'0.9.0','checks':{'database':True}}).encode()),
                    '/forum':(200,b'<html data-rendered="true">Forum</html>'),
                    '/admin/':(200,b'<script src="admin-app.js"></script>'),
                }
                status,body=paths.get(self.path,(404,b''))
                self.send_response(status);self.send_header('Content-Length',str(len(body)))
                self.end_headers();self.wfile.write(body)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',self.port),Handler)
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(self.cert,self.key)
        server.socket=ctx.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        self.addCleanup(lambda: (server.shutdown(),server.server_close(),thread.join(timeout=3)))
        op=Operations('production-test',self.site,self.cert,runner=self.run_command)
        result=op.run(require_backup=True)
        self.assertTrue(result['ok'],result)
        deep=op.run(require_backup=True, require_integrity=True)
        self.assertTrue(deep['ok'], deep)
        self.assertIn('deep_integrity', deep['checks'])
        self.assertEqual(set(result['checks']),{'containers','runtime','https','offsite_backup'})
        self.assertEqual(sum('/opt/ce-container/runtime.php' in cmd for cmd in self.commands),8)
        # Real SSL hostname validation cannot be skipped by a successful local TCP connection.
        other=self.root/'other.env';other.write_text(self.site.read_text().replace('forum.test','different.test'))
        failed=Operations('production-test',other,self.cert,runner=self.run_command).run()
        self.assertFalse(failed['checks']['https']['ok'],failed)
        self.assertTrue(failed['checks']['containers']['ok'])
        # Same real TLS peer with a certificate expiring in <7 days must fail
        # the renewal-window policy even though its hostname and CA are valid.
        expiring=Operations('production-test',self.site,self.cert,runner=self.run_command,
                            tls_min_valid_days=7).run()
        self.assertFalse(expiring['checks']['https']['ok'],expiring)
        self.assertIn('certificate expires',expiring['checks']['https']['reason'])
        # Running wrong bytes under the expected release tag is refused even if HTTP looks healthy.
        self.rows['app']['Image']='cajeerengine-forum:0.8.7-'+('a'*64)
        self.assertFalse(op.run()['checks']['containers']['ok'])

    def test_failed_dependencies_and_backup(self):
        op=Operations('production-test',self.site,self.cert,runner=self.run_command,
                      requester=lambda p:(503,b''))
        self.rows['worker']['Health']='unhealthy'
        report=op.run(require_backup=True)
        self.assertFalse(report['ok'])
        self.assertFalse(report['checks']['containers']['ok'])
        self.assertFalse(report['checks']['https']['ok'])
        self.assertTrue(report['checks']['offsite_backup']['ok'])
        self.assertEqual(Operations.parse_ps('['+','.join(json.dumps(v) for v in self.rows.values())+']')['init']['ExitCode'],0)
        with self.assertRaises(RuntimeError):
            Operations.parse_ps(json.dumps(self.rows['worker'])+'\n'+json.dumps(self.rows['worker']))

    def test_stale_offsite_backup_and_deep_integrity_fail_closed(self):
        now=int(time.time())
        for last, pending, local, remote in [
            (now-32*3600,0,'x','x'), (now+900,0,'x','x'),
            (now,1,'x','x'), (now,0,'x','y')]:
            def fake(cmd, **kwargs):
                if 'backup:automation:status' in cmd:
                    return SimpleNamespace(returncode=0,stdout=json.dumps({
                        'ok':True,'last_offsite':last,'pending':pending,
                        'latest_local_id':local,'latest_offsite_id':remote}))
                return self.run_command(cmd,**kwargs)
            op=Operations('production-test',self.site,self.cert,runner=fake,
                          requester=lambda p:(200,b''))
            with self.subTest(last=last,pending=pending,remote=remote):
                with self.assertRaises(RuntimeError): op.backups()
        def corrupt(cmd,**kwargs):
            if 'forum:ecosystem:check' in cmd:
                return SimpleNamespace(returncode=0,stdout=json.dumps({'ok':True,'deep':True,'checks':{'schema':True,'integrity':False}}))
            return self.run_command(cmd,**kwargs)
        op=Operations('production-test',self.site,self.cert,runner=corrupt,
                      requester=lambda p:(200,b''))
        self.assertFalse(op.run(require_integrity=True)['checks']['deep_integrity']['ok'])
        with self.assertRaises(ValueError):
            Operations('production-test',self.site,self.cert,tls_min_valid_days=91)
        with self.assertRaises(ValueError):
            Operations('production-test',self.site,self.cert,backup_max_age_hours=0)

    def test_literal_env_parsing_and_upgrade_queue_policy(self):
        self.assertEqual(read_site(self.site)['CE_LOOP_TIMEOUT_SECONDS'],'1800')
        for suffix in ('CE_LOOP_TIMEOUT_SECONDS=0','CE_RATE_LIMIT_GLOBAL=0',
                       'CE_RATE_LIMIT_FORUM_SEARCH=1000001','CE_DOMAIN=evil.test'):
            with self.subTest(suffix=suffix):
                self.site.write_text(self.site.read_text().split('CE_RATE_LIMIT_GLOBAL=')[0]+'CE_RATE_LIMIT_GLOBAL=120\n'+suffix+'\n')
                with self.assertRaises(ValueError): read_site(self.site)
                self.site.write_text(self.site.read_text().split('CE_RATE_LIMIT_GLOBAL=')[0]+'CE_RATE_LIMIT_GLOBAL=120\nCE_RATE_LIMIT_FORUM_SEARCH=60\n')
        original=self.site.read_text();self.site.write_text(original.replace('CE_QUEUE_DRIVER=pgsql','CE_QUEUE_DRIVER=redis'))
        self.assertEqual(read_site(self.site)['CE_QUEUE_DRIVER'],'redis')
        old=self.root/'old.env';old.write_text(self.site.read_text().replace('0.9.0','0.8.7'))
        args=SimpleNamespace(project='production-test',old_env=old,new_env=self.site,
                             state_dir=self.root,ca=None,dry_run=False,yes=False)
        with self.assertRaisesRegex(ValueError,'PostgreSQL queue'): Deployment(args)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable')
    def test_actual_runtime_watchdog_respects_batch_duration(self):
        # Exercise the exact PHP container health code without touching /tmp
        # or requiring a full database/Composer installation.
        runtime = Path(__file__).with_name('runtime.php').read_text()
        marker = self.root/'ce-loop-health'
        script = self.root/'runtime.php'
        script.write_text(runtime.replace("'/tmp/ce-loop-health'", repr(str(marker))))
        marker.touch()
        old = time.time()-500
        os.utime(marker, (old,old))
        for seconds, expected in [('600',0),('300',1),('invalid',1)]:
            result=subprocess.run(['php',str(script),'loop-health'],
                                  env=os.environ | {'CE_LOOP_TIMEOUT_SECONDS':seconds},
                                  capture_output=True)
            self.assertEqual(result.returncode,expected,(seconds,result.stderr))
        marker.unlink()
        result=subprocess.run(['php',str(script),'loop-health'],capture_output=True)
        self.assertEqual(result.returncode,1)

    def test_http_failure_and_operator_failure_are_nonzero(self):
        def fail(command, **kwargs):
            return SimpleNamespace(returncode=1,stdout='',stderr='secret key material')
        op=Operations('production-test',self.site,self.cert,runner=fail,requester=lambda p:(503,b''))
        report=op.run()
        self.assertFalse(report['ok'])
        self.assertNotIn('secret key material',json.dumps(report))


if __name__=='__main__':
    unittest.main(verbosity=2)
