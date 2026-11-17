#!/usr/bin/env python3
"""Local deployment safety tests; actual Nginx/TLS test when nginx and openssl exist."""
import json
import os
import re
from pathlib import Path
import shutil
import socket
import ssl
import stat
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch
import urllib.request
import urllib.error
import zipfile

import production as p

SITE={'domain':'deploy.example.invalid','db_host':'127.0.0.1','db_port':5432,'db_name':'cms','db_user':'cms','db_sslmode':'disable','admin_email':'admin@example.invalid'}

class Safety(unittest.TestCase):
    @unittest.skipUnless(os.geteuid()==0 and Path(p.FPM_BIN).is_file(),'requires root and PHP-FPM 8.4')
    def test_fpm_journal_socket_startup(self):
        # systemd connects stderr to an AF_UNIX stream socket. Unlike a pipe,
        # /proc/self/fd/2 cannot be opened by FPM as a writable log file.
        import pwd,grp,struct
        uid=pwd.getpwnam('nobody').pw_uid
        gid=grp.getgrnam('nogroup').gr_gid
        with tempfile.TemporaryDirectory(prefix='ce-fpm-') as d:
            d=Path(d); d.chmod(0o755)
            runtime=d/'run'; runtime.mkdir(mode=0o750); os.chown(runtime,uid,gid)
            endpoint=runtime/'php.sock'
            script=d/'index.php'; script.write_text('<?php echo "fpm-ready";')
            script.chmod(0o644)
            with patch.object(p,'USER','nobody'),patch.object(p,'GROUP','nogroup'):
                config,units,_=p.service_config(SITE)
            self.assertIn('error_log = syslog',config)
            self.assertIn('StandardError=journal',units[p.FPM])
            conf=d/'fpm.conf'
            conf.write_text(config.replace('/run/cajeerengine/php.sock',str(endpoint)))
            conf.chmod(0o644)
            parent,child=socket.socketpair()
            process=None
            try:
                process=subprocess.Popen([p.FPM_BIN,'--nodaemonize','--fpm-config',str(conf)],
                    stdout=subprocess.DEVNULL,stderr=child,user=uid,group=gid,extra_groups=[])
                child.close()
                with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as client:
                    client.settimeout(3)
                    for _ in range(50):
                        if process.poll() is not None:
                            parent.settimeout(.2)
                            self.fail('PHP-FPM exited during startup: '+parent.recv(4096).decode(errors='replace'))
                        try:
                            client.connect(str(endpoint)); break
                        except (FileNotFoundError,ConnectionRefusedError):
                            time.sleep(.1)
                    else: self.fail('PHP-FPM did not create its socket')
                    self.assertEqual(stat.S_IMODE(endpoint.stat().st_mode),0o660)
                    # Real FastCGI request, not just a socket/config test.
                    def record(kind,body):
                        return struct.pack('!BBHHBB',1,kind,1,len(body),0,0)+body
                    params={'SCRIPT_FILENAME':str(script),'SCRIPT_NAME':'/index.php',
                            'REQUEST_METHOD':'GET','REQUEST_URI':'/index.php',
                            'SERVER_PROTOCOL':'HTTP/1.1','REDIRECT_STATUS':'200'}
                    payload=b''.join(bytes((len(k),len(v)))+k.encode()+v.encode() for k,v in params.items())
                    client.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,payload)+record(4,b'')+record(5,b''))
                    with client.makefile('rb') as response:
                        stdout=b''
                        while True:
                            header=response.read(8)
                            self.assertEqual(len(header),8)
                            version,kind,request,length,padding,_=struct.unpack('!BBHHBB',header)
                            body=response.read(length); response.read(padding)
                            if kind==6: stdout+=body
                            if kind==3: break
                        self.assertIn(b'fpm-ready',stdout)
            finally:
                child.close(); parent.close()
                if process is not None and process.poll() is None:
                    process.terminate()
                    try: process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill(); process.wait(timeout=5)

    @unittest.skipUnless(shutil.which('php'),'PHP CLI unavailable')
    def test_install_client_selection_and_safe_diagnostics(self):
        installer=str(Path(p.__file__).with_name('install.php'))
        with tempfile.TemporaryDirectory() as d:
            d=Path(d)
            # Simulate pg_wrapper choosing an older local cluster unless the
            # actual PostgreSQL connection target is present in its environment.
            for binary in ('pg_dump','pg_restore'):
                f=d/binary
                f.write_text('#!/bin/sh\nif [ "$PGHOST" = db.example.invalid ] && [ "$PGPORT" = 5433 ] && [ "$LC_ALL" = C ]; then\n  echo "'+binary+' (PostgreSQL) 17.12"\nelse\n  echo "'+binary+' (PostgreSQL) 16.9"\nfi\n')
                f.chmod(0o755)
            env={**os.environ,'PATH':str(d)+':'+os.environ['PATH']}
            code='require $argv[1]; deploymentCheckClients("db.example.invalid",5433,17); echo "ok";'
            result=subprocess.run(['php','-r',code,installer],env=env,text=True,capture_output=True)
            self.assertEqual((result.returncode,result.stdout,result.stderr),(0,'ok',''))
            code='require $argv[1]; try { deploymentCheckClients("db.example.invalid",5433,18); } catch (Throwable $e) { fwrite(STDERR,deploymentInstallDiagnostic("database-client-version",$e)); exit(1); }'
            result=subprocess.run(['php','-r',code,installer],env=env,text=True,capture_output=True)
            self.assertEqual(result.returncode,1)
            self.assertIn('pg_dump major 17 is older than server major 18',result.stderr)
            code='require $argv[1]; echo deploymentInstallDiagnostic("database-connect",new PDOException("password=TOP-SECRET",28000));'
            result=subprocess.check_output(['php','-r',code,installer],text=True)
            self.assertIn('phase=database-connect',result)
            self.assertIn('sqlstate=28000',result)
            self.assertNotIn('TOP-SECRET',result)
            result=subprocess.run(['php',installer],input='{"db_password":"TOP-SECRET"}',cwd=d,text=True,capture_output=True)
            self.assertEqual(result.returncode,1)
            self.assertIn('phase=options',result.stderr)
            self.assertNotIn('TOP-SECRET',result.stderr)

    @unittest.skipUnless(os.geteuid()==0,'requires root for directory ownership test')
    def test_root_controlled_artifact_parents(self):
        import pwd
        with tempfile.TemporaryDirectory(dir='/var/lib',prefix='ce-path-test-') as d:
            d=Path(d)
            inbox=d/'inbox'; inbox.mkdir(mode=0o755)
            package=inbox/'release.zip'
            p.root_controlled_parents(package)
            for mode in (0o775,0o777):
                with self.subTest(mode=oct(mode)):
                    d.chmod(mode)
                    with self.assertRaisesRegex(ValueError,re.escape(str(d))):
                        p.root_controlled_parents(package)
                    d.chmod(0o755)
            os.chown(inbox,pwd.getpwnam('nobody').pw_uid,-1)
            with self.assertRaisesRegex(ValueError,re.escape(str(inbox))):
                p.root_controlled_parents(package)
            os.chown(inbox,0,-1)
            link=d/'link'; link.symlink_to(inbox,target_is_directory=True)
            with self.assertRaisesRegex(ValueError,re.escape(str(link))):
                p.root_controlled_parents(link/'release.zip')
            p.root_controlled_parents(package)

    @unittest.skipUnless(shutil.which('php'),'PHP CLI unavailable')
    def test_php_preflight(self):
        # Exercise the actual PHP probe, including Phar's mixed-case module name.
        required=('pdo_pgsql','sodium','mbstring','fileinfo','openssl','phar','gd')
        for options in ([],['-n']):
            with self.subTest(options=options):
                command=['php',*options,'-r']
                info=json.loads(subprocess.check_output(command+[
                    'echo json_encode([PHP_VERSION_ID, PHP_VERSION, get_loaded_extensions()]);'
                ],text=True))
                version_id,version,modules=info
                loaded={name.lower() for name in modules}
                missing=[name for name in required if name not in loaded]
                errors=[]
                if not 80401<=version_id<90000:
                    errors.append(f'Unsupported PHP version: {version} (required >=8.4.1 <9.0)')
                if missing:
                    errors.append('Missing PHP extensions: '+', '.join(missing))
                result=subprocess.run(command+[p.PHP_PREFLIGHT],text=True,capture_output=True)
                self.assertEqual(result.returncode,1 if errors else 0,result.stderr)
                self.assertEqual(result.stderr.strip().splitlines(),errors)

    def test_config_and_journal(self):
        with tempfile.TemporaryDirectory() as d, patch.object(p,'ETC',Path(d)), patch.object(p,'JOURNAL',Path(d)/'journal'):
            path=Path(d)/'site.json'; path.write_text(json.dumps(SITE))
            with patch.object(p,'root_file',lambda path,*a:path):
                self.assertEqual(p.config(),SITE)
                path.write_text(json.dumps({**SITE,'domain':'x; include /etc/passwd;'}))
                with self.assertRaises(ValueError): p.config()
                path.write_text(json.dumps({**SITE,'db_host':'db.remote','db_sslmode':'disable'}))
                with self.assertRaises(ValueError): p.config()
            p.record('deploying',version='1.2.5')
            self.assertEqual(json.loads(p.JOURNAL.read_text())['state'],'deploying')
            self.assertEqual(stat.S_IMODE(p.JOURNAL.stat().st_mode),0o600)

    def test_extract_rejects_unsafe(self):
        for name,mode in [('cajeerengine/../../escape',stat.S_IFREG),('cajeerengine/public/link',stat.S_IFLNK),('cajeerengine/a\\b',stat.S_IFREG),('/etc/passwd',stat.S_IFREG),('cajeerengine/a/',stat.S_IFDIR)]:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as d:
                d=Path(d); z=d/'bad.zip'; stage=d/'stage'; stage.mkdir()
                with zipfile.ZipFile(z,'w') as f:
                    i=zipfile.ZipInfo(name); i.create_system=3; i.external_attr=(mode|0o777)<<16; f.writestr(i,'bad')
                with self.assertRaises(ValueError): p.extract(z,stage)
                self.assertFalse((d/'escape').exists())

    def test_extract_duplicate(self):
        import warnings
        with tempfile.TemporaryDirectory() as d:
            d=Path(d); z=d/'bad.zip'; stage=d/'stage'; stage.mkdir()
            with warnings.catch_warnings(), zipfile.ZipFile(z,'w') as f:
                warnings.simplefilter('ignore'); f.writestr('cajeerengine/a','one'); f.writestr('cajeerengine/a','two')
            with self.assertRaises(ValueError): p.extract(z,stage)

    @unittest.skipUnless(os.geteuid()==0,'requires root for ownership test')
    def test_extract_valid(self):
        import pwd,grp
        with tempfile.TemporaryDirectory() as d, patch.object(p,'USER','nobody'), patch.object(p,'GROUP','nogroup'):
            d=Path(d); z=d/'ok.zip'; stage=d/'stage'; stage.mkdir()
            with zipfile.ZipFile(z,'w') as f:
                f.writestr('cajeerengine/bin/cajeer','code'); f.writestr('cajeerengine/public/index.php','index')
            p.extract(z,stage)
            self.assertEqual((stage/'public/index.php').read_text(),'index')
            self.assertEqual((stage/'public/index.php').stat().st_uid,pwd.getpwnam('nobody').pw_uid)
            self.assertEqual(stat.S_IMODE((stage/'bin/cajeer').stat().st_mode),0o750)

    @unittest.skipUnless(shutil.which('php'),'PHP CLI unavailable')
    def test_forum_identity(self):
        verify=Path(__file__).with_name('verify.php').resolve()
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); (root/'VERSION').write_text('1.3.0\n')
            release={'edition':'forum','edition_version':'0.8.1','upstream_version':'1.3.0','version':'1.3.0'}
            (root/'release.json').write_text(json.dumps(release))
            def read(): return subprocess.run(['php',str(verify),'--identity'],cwd=root,text=True,capture_output=True)
            result=read(); self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(json.loads(result.stdout),{'edition':'forum','edition_version':'0.8.1','upstream_version':'1.3.0'})
            for changes in ({'edition':'cms'},{'upstream_version':'0.8.1'},{'version':'0.8.1'},{'edition_version':'not-a-version'}):
                (root/'release.json').write_text(json.dumps(release|changes)); self.assertNotEqual(read().returncode,0)
        with patch.object(p,'app_run',return_value=result.stdout): self.assertEqual(p.current_version(),'0.8.1')
        self.assertLess(p.version_tuple('0.8.0'),p.version_tuple('0.8.1'))
        for value in ('1.03.0','0.8.1-rc1','0.8'):
            with self.assertRaises(ValueError): p.version_tuple(value)

    @unittest.skipUnless(shutil.which('php'),'PHP CLI unavailable')
    def test_signed_foreign_artifact_rejected(self):
        import hashlib
        root=Path(__file__).resolve().parent.parent
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp); secret=d/'sign.key'; public=d/'sign.pub'; package=d/'candidate.zip'
            code=r'require $argv[1]."/core/Update/OperationFiles.php"; require $argv[1]."/core/Update/ReleaseSignature.php"; \CajeerEngine\Update\ReleaseSignature::generate($argv[2],$argv[3]);'
            subprocess.run(['php','-r',code,str(root),str(secret),str(public)],check=True)
            sign=r'require $argv[1]."/core/Update/OperationFiles.php"; require $argv[1]."/core/Update/ReleaseSignature.php"; \CajeerEngine\Update\ReleaseSignature::sign($argv[2],$argv[3]);'
            for edition,mode in [('cms','dist'),('forum','source')]:
                with zipfile.ZipFile(package,'w') as z:
                    z.writestr('cajeerengine/release-manifest.json',json.dumps({'edition':edition,'mode':mode,'version':'0.8.1','upstream_version':'1.3.0'}))
                subprocess.run(['php','-r',sign,str(root),str(package),str(secret)],check=True)
                digest=hashlib.sha256(package.read_bytes()).hexdigest()
                cmd=['php',str(root/'deploy/verify.php'),str(package),str(public),digest,'0.8.1']
                result=subprocess.run(cmd,text=True,capture_output=True)
                self.assertNotEqual(result.returncode,0); self.assertIn('Forum Edition dist required',result.stderr)
                with package.open('ab') as stream: stream.write(b'tamper')
                result=subprocess.run(cmd,text=True,capture_output=True)
                self.assertNotEqual(result.returncode,0); self.assertIn('signature or SHA256 mismatch',result.stderr)

    def test_read_only_host_operations_gate(self):
        with tempfile.TemporaryDirectory() as folder:
            base=Path(folder);(base/'app').mkdir()
            for path in ('fpm','worker'):(base/path).touch()
            journal=base/'journal';journal.write_text(json.dumps({'edition':'forum',
                'state':'ready','site':SITE,'version':'0.9.3','sha256':'a'*64}))
            identity=json.dumps({'edition':'forum','edition_version':'0.9.3','upstream_version':'1.3.0'})
            response={'status':'ok','edition_version':'0.9.3','version':'1.3.0','checks':{'database':True}}
            commands=[]
            def fake_run(*args, **kwargs):
                commands.append(args)
                if args[0]=='curl' and '--write-out' in args:
                    return '404' if args[-1].endswith(('/.env','/install/','/upgrade/')) else '200'
                if args[0]=='curl':return json.dumps(response)
                return ''
            def fake_app(*args, **kwargs):
                if '--identity' in args:return identity
                return json.dumps({'ok':True,'last_offsite':1234,'pending':0})
            with patch.object(p,'GATE',base/'gate'),patch.object(p,'FPM_ALLOWED',base/'fpm'),patch.object(p,'WORKERS_ALLOWED',base/'worker'),patch.object(p,'APP',base/'app'),patch.object(p,'JOURNAL',journal),patch.object(p,'run',side_effect=fake_run),patch.object(p,'app_run',side_effect=fake_app),patch.object(p,'verify_installed') as verify,patch.object(p,'current_version',return_value='0.9.3'),patch.object(p,'health'):
                result=p.verify_host(SITE,require_backup=True)
                self.assertTrue(result['ok'])
                verify.assert_called_once_with('0.9.3','a'*64)
                self.assertEqual(sum(c[0]=='curl' for c in commands),8)
                (base/'gate').touch()
                with self.assertRaisesRegex(RuntimeError,'gated'):p.verify_host(SITE)
                (base/'gate').unlink()
                journal.write_text(json.dumps({'edition':'forum','state':'deploying','site':SITE,'version':'0.9.3','sha256':'a'*64}))
                with self.assertRaisesRegex(RuntimeError,'not committed'):p.verify_host(SITE)

    def test_forum_readiness_semantics(self):
        payload={'version':'1.3.0','edition_version':'0.8.1','status':'ok','checks':{'database':True}}
        self.assertTrue(p.validate_health_payload(payload,'0.8.1','1.3.0'))
        for changes in ({'edition_version':'0.8.0'},{'version':'0.8.1'},{'checks':{'database':False}},{'status':'degraded'},{'checks':{}}):
            self.assertFalse(p.validate_health_payload(payload|changes,'0.8.1','1.3.0'))
        good={'/api/v1/forum':json.dumps({'data':{'boards':[],'categories':[]}}),'/forum':'<div data-rendered="true"><style id="forum-theme"></style><script src="/forum-assets/forum.js"></script></div>','/admin/':'<script src="admin-app.js"></script>'}
        with patch.object(p,'local_probe',side_effect=good.__getitem__): p.forum_probe()
        for path,value in (('/api/v1/forum','{"data":[]}'),('/forum','generic CMS'),('/admin/','not ACP')):
            responses=good|{path:value}
            with patch.object(p,'local_probe',side_effect=responses.__getitem__),self.assertRaises(RuntimeError): p.forum_probe()

    def test_preflight_never_gates_or_publishes(self):
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as d:
            root=Path(d); (root/'install-secrets.json').write_text(json.dumps({'db_password':'x'*32,'admin_password':'StrongPass123!'}))
            args=SimpleNamespace(artifact=root/'signed.zip',sha256='a'*64,version='0.8.1',dry_run=True,previous_sha256=None)
            def command(*a,**kw):
                if '--preflight' in a: raise RuntimeError('database unavailable')
            with patch.object(p,'ETC',root),patch.object(p,'APP',root/'app'),patch.object(p,'JOURNAL',root/'journal'),patch.object(p,'GATE',root/'gate'),patch.object(p,'FPM_BIN',str(root/'fpm')),patch.object(p,'artifact',return_value=args.artifact),patch.object(p,'validate_host_material'),patch.object(p,'root_file',side_effect=lambda path,*a:path),patch.object(p.shutil,'which',return_value='/usr/bin/tool'),patch.object(p,'run',side_effect=command),patch.object(p,'gate_on') as gate:
                (root/'fpm').touch()
                with self.assertRaisesRegex(RuntimeError,'database unavailable'): p.deploy(args,SITE)
                gate.assert_not_called(); self.assertFalse(p.APP.exists())
                with patch.object(p,'run') as run,patch('builtins.print') as output:
                    p.deploy(args,SITE)
                    self.assertTrue(any('--preflight' in call.args for call in run.call_args_list))
                    self.assertEqual(json.loads(output.call_args.args[0])['edition_version'],'0.8.1')
                gate.assert_not_called(); self.assertFalse(p.APP.exists())

    def test_activation_failure_keeps_gate(self):
        with tempfile.TemporaryDirectory() as d, patch.object(p,'GATE',Path(d)/'maintenance'), patch.object(p,'STATE',Path(d)), patch.object(p,'JOURNAL',Path(d)/'journal'), patch.object(p,'WORKERS_ALLOWED',Path(d)/'workers'), patch.object(p,'FPM_ALLOWED',Path(d)/'fpm'):
            p.gate_on()
            with patch.object(p,'verify_installed'),patch.object(p,'current_version',return_value='1.2.5'),patch.object(p,'app_run'),patch.object(p,'run'),patch.object(p,'health',side_effect=RuntimeError('not ready')):
                with self.assertRaises(RuntimeError): p.activate('1.2.5','a'*64,SITE)
            self.assertTrue(p.GATE.exists()); self.assertFalse(p.JOURNAL.exists())
            with patch.object(p,'verify_installed'),patch.object(p,'current_version',return_value='1.2.5'),patch.object(p,'app_run',return_value=json.dumps({'timestamp':int(time.time())+1,'status':'success'})),patch.object(p,'run'),patch.object(p,'health'):
                p.activate('1.2.5','a'*64,SITE)
            self.assertFalse(p.GATE.exists()); self.assertEqual(json.loads(p.JOURNAL.read_text())['sha256'],'a'*64)

    @unittest.skipUnless(shutil.which('nginx') and shutil.which('openssl'),'nginx/openssl unavailable')
    def test_real_nginx_tls_gate(self):
        # This is a real Nginx process, not a mock. PHP-FPM/DB are exercised in the separate host smoke.
        with tempfile.TemporaryDirectory() as d:
            d=Path(d); d.chmod(0o755); etc=d/'etc'; etc.mkdir(); (etc/'tls').mkdir(); app=d/'app'; app.mkdir(); state=d/'state'; state.mkdir()
            public=app/'public'; public.mkdir(); (public/'admin/assets').mkdir(parents=True); (public/'uploads').mkdir(); (public/'install').mkdir()
            (public/'forum-assets').mkdir(); (public/'forum-assets/forum.js').write_text('forum asset'); (public/'admin/index.html').write_text('admin'); (public/'admin/assets/admin-app.js').write_text('asset'); (public/'uploads/secret.php').write_text('secret'); (public/'install/index.php').write_text('install'); (public/'index.php').write_text('index'); (public/'.env').write_text('secret')
            subprocess.run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','2','-subj','/CN=deploy.example.invalid','-addext','subjectAltName=DNS:deploy.example.invalid','-keyout',str(etc/'tls/privkey.pem'),'-out',str(etc/'tls/fullchain.pem')],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            def port():
                with socket.socket() as s: s.bind(('127.0.0.1',0)); return s.getsockname()[1]
            http,tls,local=port(),port(),port()
            with patch.object(p,'APP',app),patch.object(p,'ETC',etc),patch.object(p,'GATE',state/'maintenance'),patch.object(p,'WORKERS_ALLOWED',state/'workers'),patch.object(p,'FPM_ALLOWED',state/'fpm'):
                _,_,nginx=p.service_config(SITE)
                nginx=nginx.replace('listen 80;','listen 127.0.0.1:'+str(http)+';').replace('listen 443 ssl;','listen 127.0.0.1:'+str(tls)+' ssl;').replace('127.0.0.1:18085','127.0.0.1:'+str(local))
                conf=d/'nginx.conf'; conf.write_text(f'pid {d}/nginx.pid; error_log {d}/error.log; events {{ worker_connections 32; }} http {{ access_log off; client_body_temp_path {d}/body; fastcgi_temp_path {d}/fastcgi; proxy_temp_path {d}/proxy; uwsgi_temp_path {d}/uwsgi; scgi_temp_path {d}/scgi; {nginx} }}')
                subprocess.run(['nginx','-t','-p',str(d),'-c',str(conf)],check=True,stdout=subprocess.DEVNULL)
                process=subprocess.Popen(['nginx','-p',str(d),'-c',str(conf),'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                try:
                    for _ in range(30):
                        try:
                            with socket.create_connection(('127.0.0.1',tls),timeout=.2): break
                        except OSError: time.sleep(.1)
                    def request(path):
                        req=urllib.request.Request('https://127.0.0.1:'+str(tls)+path,headers={'Host':SITE['domain']})
                        try:
                            with urllib.request.urlopen(req,context=ssl._create_unverified_context(),timeout=3) as r:
                                if r.status==200 and path.startswith('/admin/'):
                                    self.assertEqual(r.headers.get('X-Content-Type-Options'),'nosniff')
                                    self.assertEqual(r.headers.get('X-Frame-Options'),'SAMEORIGIN')
                                    self.assertIn('frame-ancestors',r.headers.get('Content-Security-Policy',''))
                                    self.assertEqual(r.headers.get('Strict-Transport-Security'),'max-age=31536000')
                                return r.status,r.read()
                        except urllib.error.HTTPError as e: return e.code,e.read()
                    self.assertEqual(request('/admin/assets/admin-app.js'),(200,b'asset'))
                    self.assertEqual(request('/admin/deep')[0],200)
                    self.assertEqual(request('/forum-assets/forum.js'),(200,b'forum asset'))
                    self.assertEqual(request('/forum-assets/missing.js')[0],404)
                    for path in ('/uploads/secret.php','/install/index.php','/.env','/upgrade/','/admin/assets/missing.js'):
                        self.assertEqual(request(path)[0],404,path)
                    p.gate_on(); self.assertEqual(request('/admin/assets/admin-app.js')[0],503)
                    self.assertEqual(request('/forum-assets/forum.js')[0],503)
                    self.assertEqual(request('/forum')[0],503)
                    def local_request(path, method='GET'):
                        req=urllib.request.Request('http://127.0.0.1:'+str(local)+path,method=method)
                        try:
                            with urllib.request.urlopen(req,timeout=3) as r: return r.status,r.read()
                        except urllib.error.HTTPError as e: return e.code,e.read()
                    self.assertEqual(local_request('/admin/'),(200,b'admin'))
                    self.assertEqual(local_request('/admin/','POST')[0],403)
                    self.assertEqual(local_request('/api/v1/forum','POST')[0],403)
                    self.assertEqual(local_request('/api/v1/auth/login')[0],404)
                    self.assertEqual(local_request('/.env')[0],404)
                    p.GATE.unlink(); self.assertEqual(request('/admin/assets/admin-app.js')[0],200)
                finally:
                    process.terminate(); process.wait(timeout=10)

if __name__=='__main__': unittest.main(verbosity=2)
