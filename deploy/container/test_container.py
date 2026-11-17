#!/usr/bin/env python3
"""Local regression: actual TLS nginx, supervisor subprocesses, release policy.
The real Docker/PDO end-to-end acceptance is a separate required CI job.
"""
import fcntl, http.client, os, pathlib, shutil, signal, socket, ssl, struct, threading, subprocess, tempfile, time, unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
HERE=pathlib.Path(__file__).resolve().parent

class ContainerTests(unittest.TestCase):
    def test_compose_topology(self):
        import yaml
        cfg=yaml.safe_load((HERE/'compose.yaml').read_text()); s=cfg['services']
        self.assertEqual(set(s),{'postgres','redis','init','app','worker','scheduler','gateway'})
        self.assertEqual([n for n,v in s.items() if v.get('ports')],['gateway'])
        self.assertTrue(cfg['networks']['backend']['internal'])
        for n, service in s.items():
            self.assertNotIn('privileged',service,n)
            self.assertNotIn('network_mode',service,n)
            self.assertIn('ALL',service['cap_drop'],n)
            self.assertIn('no-new-privileges:true',service['security_opt'],n)
            for mount in service.get('volumes',[]):
                self.assertNotIn('docker.sock',mount,n)
        for n in ('postgres','redis'):
            self.assertNotIn('frontend',s[n]['networks'],n)
            self.assertEqual(['backend'],s[n]['networks'],n)
            self.assertNotIn('ports',s[n],n)
            self.assertNotIn('keys:/run/ce-keys:ro',s[n].get('volumes',[]))
        for n in ('app','worker','scheduler','gateway'):
            self.assertNotIn('db_password',s[n].get('secrets',[]))
            self.assertNotIn('backup_key',s[n].get('secrets',[]))
        self.assertEqual(s['gateway']['secrets'],['tls_certificate','tls_key'])
        for n in ('app','worker','scheduler'):
            self.assertTrue(s[n]['read_only']); self.assertEqual(s[n]['user'],'33:33')
            self.assertIn('release:/srv/forum:ro',s[n]['volumes'])
            self.assertIn('storage:/srv/forum/storage',s[n]['volumes'])
            self.assertNotIn('secrets',s[n]); self.assertIn('healthcheck',s[n])
        self.assertEqual(s['app']['depends_on']['init']['condition'],'service_completed_successfully')
        self.assertTrue(all(s['gateway']['depends_on'][n]['condition']=='service_healthy' for n in ('app','worker','scheduler')))
        self.assertEqual(s['init']['environment']['CE_QUEUE_DRIVER'],'${CE_QUEUE_DRIVER:-pgsql}')
        self.assertIn('POSTGRES_PASSWORD_FILE', (HERE/'postgres-start.sh').read_text())
        self.assertIn('NOSUPERUSER NOCREATEROLE', (HERE/'postgres-init.sh').read_text())
        self.assertIn('appendonly yes', (HERE/'redis-start.sh').read_text())
        self.assertIn('maxmemory-policy noeviction', (HERE/'redis-start.sh').read_text())

    def test_backup_operator_and_overlay(self):
        import yaml
        from unittest.mock import patch
        import backup as b
        import uuid
        cfg=yaml.safe_load((HERE/'backup.compose.yaml').read_text())['services']['backup']
        self.assertEqual(cfg['profiles'],['backup']); self.assertTrue(cfg['read_only']); self.assertEqual(cfg['user'],'33:33')
        self.assertNotIn('ports',cfg); self.assertNotIn('privileged',cfg); self.assertIn('release:/srv/forum:ro',cfg['volumes'])
        self.assertEqual(cfg['build']['target'],'backup'); self.assertEqual(cfg['entrypoint'],['/opt/ce-container/backup.sh'])
        with tempfile.TemporaryDirectory() as d:
            d=pathlib.Path(d).resolve(); config=d/'config'; off=d/'offsite'; config.mkdir();off.mkdir();(config/'policy.json').write_text('{}')
            site=d/'site.env'; site.write_text('CE_RELEASE_VERSION=0.8.4\nCE_RELEASE_SHA256='+'a'*64+'\nCE_DOMAIN=forum.test\nCE_ADMIN_EMAIL=admin@example.invalid\nCE_NAMESPACE=backup-ci\nCE_SECRETS_DIR=/secure\n')
            project='backup-test-'+uuid.uuid4().hex[:12]
            args=['backup.py','run','--project',project,'--env-file',str(site),'--config-dir',str(config),'--offsite-dir',str(off),'--force']
            with patch('sys.argv',args),patch.object(b.subprocess,'check_output',return_value='unix:///var/run/docker.sock\n'),patch.object(b.subprocess,'run') as run:
                b.main(); command=run.call_args.args[0]; self.assertEqual(command[-5:],['run','--rm','--no-deps','backup','--force']); self.assertIn(str(HERE/'backup.compose.yaml'),command)
                self.assertEqual(run.call_args.kwargs['env']['CE_BACKUP_CONFIG_DIR'],str(config)); self.assertEqual(run.call_args.kwargs['env']['CE_BACKUP_OFFSITE_DIR'],str(off))
                with b.project_lock(project):
                    with self.assertRaises(BlockingIOError): b.main()
                site.write_text(site.read_text()+'CE_QUEUE_DRIVER=redis\n')
                with self.assertRaises(ValueError): b.main()
            pathlib.Path('/tmp/cajeer-forum-'+project+'.update.lock').unlink()

    def test_release_policy(self):
        script='''require "core/Release/ReleaseFilePolicy.php"; use CajeerEngine\\Release\\ReleaseFilePolicy as P;
        foreach ([".dockerignore","deploy/container/Dockerfile","deploy/container/compose.yaml","deploy/container/bootstrap.php","deploy/container/smoke.py","deploy/container/test_container.py","tools/forum-security-hardening-regression.php"] as $p) if(!P::allows($p,"source")) exit(1);
        foreach (["deploy/container/artifacts/release.zip","deploy/container/site.env","deploy/container/artifacts/release.pub","deploy/container/secrets/password"] as $p) if(P::allows($p,"source")) exit(2);
        if(!P::traverses("deploy/container","source")) exit(3);'''
        subprocess.run(['php','-r',script],cwd=ROOT,check=True)

    def test_supervised_worker(self):
        with tempfile.TemporaryDirectory() as d:
            d=pathlib.Path(d); fake=d/'php'; fake.write_text('#!/bin/sh\necho "$*" >> "'+str(d/'calls')+'"\nexit 0\n'); fake.chmod(0o755)
            script=d/'loop.sh'; script.write_text((HERE/'loop.sh').read_text().replace('/srv/forum',str(d)).replace('/tmp/ce-loop-',str(d/'ce-loop-')))
            proc=subprocess.Popen(['sh',str(script),'worker'],env=os.environ|{'PATH':str(d)+':'+os.environ['PATH']})
            try:
                for _ in range(50):
                    if (d/'ce-loop-health').exists(): break
                    time.sleep(.05)
                self.assertTrue((d/'ce-loop-health').exists()); self.assertIn('queue:work --queue=default --limit=10',(d/'calls').read_text())
                proc.send_signal(signal.SIGTERM); self.assertEqual(proc.wait(timeout=5),0)
            finally:
                if proc.poll() is None: proc.kill(); proc.wait()

    @unittest.skipUnless(shutil.which('nginx') and shutil.which('openssl') and os.geteuid()==0,'real nginx TLS requires root, nginx, openssl')
    def test_nginx_tls_and_gates(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=pathlib.Path(tmp); d.chmod(0o755); root=d/'forum'; public=root/'public'; app=root/'storage/app'
            for path in (public/'admin/assets',public/'forum-assets',public/'uploads',app/'updates',app/'extensions'):
                path.mkdir(parents=True,exist_ok=True)
            (public/'admin/index.html').write_text('ACP admin-app.js'); (public/'forum-assets/forum.js').write_text('forum'); (public/'uploads/shell.php').write_text('secret')
            (root/'.env').write_text('private'); (root/'.env').chmod(0o600)
            cert=d/'cert.pem'; key=d/'key.pem'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
            # Real Nginx FastCGI framing; local PHP CLI executes the exact gate
            # function because PHP-FPM is not available in this environment.
            backend=socket.socket(); backend.bind(('127.0.0.1',0)); backend.listen(); backend.settimeout(.2)
            backend_port=backend.getsockname()[1]; finished=threading.Event()
            def fcgi_record(kind,rid,payload=b''):
                return struct.pack('!BBHHBB',1,kind,rid,len(payload),0,0)+payload
            def exact(c,n):
                out=b''
                while len(out)<n:
                    chunk=c.recv(n-len(out))
                    if not chunk: raise EOFError()
                    out+=chunk
                return out
            def serve():
                while not finished.is_set():
                    try: conn,_=backend.accept()
                    except socket.timeout: continue
                    except OSError: break
                    with conn:
                        conn.settimeout(3)
                        try:
                            params=b''
                            while True:
                                v,kind,rid,length,pad,_=struct.unpack('!BBHHBB',exact(conn,8))
                                payload=exact(conn,length); exact(conn,pad)
                                if kind==4: params+=payload
                                if kind==5 and not length: break
                            if b'/opt/ce-container/gate.php' in params:
                                php='require $argv[1]; echo forumContainerGate($argv[2]);'
                                status=int(subprocess.check_output(['php','-r',php,str(HERE/'gate.php'),str(root)]))
                                response=f'Status: {status}\r\nContent-Type: text/plain\r\n\r\n'.encode()
                            else:
                                response=b'Status: 404\r\nContent-Type: text/plain\r\n\r\nnot found'
                            conn.sendall(fcgi_record(6,rid,response)+fcgi_record(6,rid)+fcgi_record(3,rid,b'\0'*8))
                        except (EOFError,OSError): pass
            server=threading.Thread(target=serve,daemon=True); server.start()
            text=(HERE/'nginx.conf.template').read_text().replace('user nginx;','user www-data;').replace('worker_processes auto;','worker_processes 1;').replace('/tmp/nginx.pid',str(d/'nginx.pid')).replace('/tmp/client_body',str(d/'client_body')).replace('/tmp/fastcgi',str(d/'fastcgi')).replace('/srv/forum',str(root)).replace('/run/secrets/tls_certificate',str(cert)).replace('/run/secrets/tls_key',str(key)).replace('listen 8443','listen 127.0.0.1:'+str(port)).replace('${CE_DOMAIN}','localhost').replace('${CE_HTTPS_PORT}',str(port)).replace('app:9000','127.0.0.1:'+str(backend_port))
            conf=d/'nginx.conf'; conf.write_text(text)
            subprocess.run(['nginx','-t','-c',str(conf)],check=True,capture_output=True)
            proc=subprocess.Popen(['nginx','-c',str(conf),'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
            ctx=ssl.create_default_context(cafile=str(cert))
            def call(path,host='localhost'):
                c=http.client.HTTPSConnection('localhost',port,context=ctx,timeout=3)
                try: c.request('GET',path,headers={'Host':host}); r=c.getresponse(); return r.status,dict(r.getheaders()),r.read()
                finally: c.close()
            try:
                for _ in range(50):
                    try: call('/admin/'); break
                    except OSError: time.sleep(.05)
                self.assertEqual(call('/admin/')[0],503)
                self.assertEqual(call('/forum-assets/forum.js')[0],503)
                (app/'installed.lock').write_text('{}'); (app/'installed.lock').chmod(0o600)
                status,headers,body=call('/admin/'); self.assertEqual(status,200); self.assertIn(b'ACP',body); self.assertIn('frame-ancestors',headers['Content-Security-Policy'])
                self.assertEqual(call('/forum-assets/forum.js')[0],200)
                for path in ('/.env','/install/','/upgrade/','/uploads/shell.php'): self.assertEqual(call(path)[0],404,path)
                self.assertEqual(call('/admin/','invalid.example')[0],421)
                self.assertEqual(call('/admin')[1]['Location'],'/admin/')
                for marker in ('maintenance.json','updates/active.json','backup-restore.json','extensions/deployment.json'):
                    f=app/marker; f.write_text('{}'); f.chmod(0o600); self.assertEqual(call('/admin/')[0],503); self.assertEqual(call('/forum-assets/forum.js')[0],503); f.unlink()
                self.assertEqual(call('/admin/')[0],200)
                with (app/'operations.lock').open('w') as lock:
                    fcntl.flock(lock,fcntl.LOCK_EX); self.assertEqual(call('/admin/')[0],503)
                self.assertEqual(call('/admin/')[0],200)
            finally:
                proc.terminate(); proc.wait(timeout=5); proc.stderr.close(); finished.set(); backend.close(); server.join(timeout=2)

if __name__=='__main__': unittest.main(verbosity=2)
