#!/usr/bin/env python3
"""Test config, dashboard and real Prometheus -> Alertmanager -> HTTPS webhook integration.

--integration requires rootful Docker (Linux host-gateway), PHP PDO SQLite and openssl.
The HTTPS server forwards /metrics to the real front controller of a disposable SQL forum.
Only the test copy of the rule durations is shortened; the original rules are tested by promtool.
"""
import argparse
import base64
import http.server
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
RES = ROOT / 'resources/observability'
spec = importlib.util.spec_from_file_location('configure', HERE / 'configure.py')
config = importlib.util.module_from_spec(spec); spec.loader.exec_module(config)


def create_secrets(directory, token, password, webhook):
    for name, value in [('metrics-token',token),('grafana-password',password),('alert-webhook',webhook)]:
        path=directory/name;path.write_text(value+'\n');path.chmod(0o600)


class ConfigurationTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.d=Path(self.tmp.name)
        self.token=secrets.token_hex(32);self.password=secrets.token_hex(16)
        self.hook='https://alerts.example.test/receiver?token=secret-value'
        create_secrets(self.d,self.token,self.password,self.hook)
        self.ca=self.d/'input-ca.pem';self.ca.write_bytes(Path('/etc/ssl/certs/ca-certificates.crt').read_bytes())
    def tearDown(self): self.tmp.cleanup()
    def test_generated_config(self):
        config.configure(self.d,'forum.example.test:443',self.ca)
        p=(self.d/'prometheus.json').read_text();a=(self.d/'alertmanager.json').read_text()
        self.assertNotIn(self.token,p);self.assertNotIn(self.hook,a);self.assertNotIn(self.password,p+a)
        self.assertFalse(json.loads(p)['scrape_configs'][0]['tls_config']['insecure_skip_verify'])
        self.assertEqual(json.loads(a)['receivers'][0]['webhook_configs'][0]['url_file'],'/run/secrets/alert-webhook')
        self.assertTrue(json.loads(a)['receivers'][0]['webhook_configs'][0]['send_resolved'])
        blackbox=json.loads((self.d/'blackbox.json').read_text())
        self.assertTrue(blackbox['modules']['forum_https']['http']['fail_if_not_ssl'])
        self.assertFalse(blackbox['modules']['forum_https']['http']['tls_config']['insecure_skip_verify'])
        tls=json.loads(p)['scrape_configs'][1]
        self.assertEqual(tls['job_name'],'forum-tls')
        self.assertEqual(tls['static_configs'][0]['targets'],['https://forum.example.test:443/health/ready'])
    def test_invalid_target_and_secret(self):
        for bad in ('http://example.test','forum.test:0','forum.test:65536','forum.test:443/path','forum..test:443'):
            with self.assertRaises(ValueError): config.configure(self.d,bad,self.ca)
        (self.d/'metrics-token').chmod(0o644)
        with self.assertRaises(ValueError): config.configure(self.d,'forum.test:443',self.ca)
        (self.d/'metrics-token').chmod(0o600);(self.d/'alert-webhook').write_text('http://alerts.example.test/')
        with self.assertRaises(ValueError): config.configure(self.d,'forum.test:443',self.ca)
    def test_no_symlink_or_secret_ca(self):
        (self.d/'metrics-token').unlink();(self.d/'metrics-token').symlink_to(self.ca)
        with self.assertRaises(ValueError): config.configure(self.d,'forum.test:443',self.ca)
        (self.d/'metrics-token').unlink();create_secrets(self.d,self.token,self.password,self.hook)
        self.ca.write_text('-----BEGIN CERTIFICATE-----\nPRIVATE KEY\n')
        with self.assertRaises(ValueError): config.configure(self.d,'forum.test:443',self.ca)
    def test_dashboard_queries_and_datasources(self):
        for path in (RES/'grafana/dashboards').glob('*.json'):
            data=json.loads(path.read_text());self.assertGreaterEqual(len(data['panels']),8)
            self.assertEqual(len({p['id'] for p in data['panels']}),len(data['panels']))
            for panel in data['panels']:
                self.assertEqual(panel['datasource']['uid'],'cajeer-prometheus')
                self.assertTrue(all(t['expr'] and 'example' not in t['expr'] for t in panel['targets']))


def wait_until(fn,seconds=90):
    end=time.monotonic()+seconds;last=None
    while time.monotonic()<end:
        try:
            result=fn()
            if result: return result
        except (OSError,ValueError,KeyError,urllib.error.URLError) as exc: last=exc
        time.sleep(1)
    raise RuntimeError(f'Wait timed out: {last}')


def integration():
    def run(*args,**kwargs): return subprocess.run(args,check=True,**kwargs)
    def free_port():
        with socket.socket() as sock: sock.bind(('127.0.0.1',0));return sock.getsockname()[1]
    # Real rule unit tests, including firing and resolution thresholds.
    run('docker','run','--rm','-v',f'{ROOT}:/repo:ro','-w','/repo/deploy/monitoring','--entrypoint','/bin/promtool','prom/prometheus:v3.5.0','test','rules','rules-test.yml')
    with tempfile.TemporaryDirectory(prefix='ce-monitor-') as tmp:
        d=Path(tmp);site=d/'site';site.mkdir();cfg=d/'config';cfg.mkdir(mode=0o700)
        for name in ['core','config']: shutil.copytree(ROOT/name,site/name)
        for name in ['VERSION','release.json']: shutil.copyfile(ROOT/name,site/name)
        for name in ['public','storage/app','storage/cache','storage/logs','storage/queue','public/uploads']: (site/name).mkdir(parents=True,exist_ok=True)
        shutil.copyfile(ROOT/'public/index.php',site/'public/index.php')
        db=site/'forum.sqlite';now=int(time.time())
        with sqlite3.connect(db) as con:
            for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')): con.executescript(f.read_text())
            con.execute("INSERT INTO ce_forum_extension_events(id,event_name,payload,created_at,due_at,state,targets,progress) VALUES(?,?,?,?,?,?,?,?)",('a'*32,'forum.thread.create','{}',now-900,now-600,'pending','[]','{}'))
        token=secrets.token_hex(32);password=secrets.token_hex(16);php_port=free_port();https_port=free_port()
        pp,ap,gp=free_port(),free_port(),free_port()
        (site/'.env').write_text(f'DB_DRIVER=sqlite\nSQLITE_DATABASE={db}\nOBSERVABILITY_METRICS_TOKEN={token}\nQUEUE_DRIVER=file\nCACHE_DRIVER=file\nSEARCH_DRIVER=sqlite\n')
        run('php','-r','require "core/Console/NativeCli.php"; (new CajeerEngine\\Observability\\Metrics(getcwd()))->observe("http","api.v1.forum.search","2xx",0.7);',cwd=site)
        php_log=(d/'php.log').open('w');php=subprocess.Popen(['php','-S',f'127.0.0.1:{php_port}','-t',str(site/'public')],cwd=site,stdout=php_log,stderr=subprocess.STDOUT)
        events=[]
        # These endpoints are local disposable fixtures, never HTTP proxy targets.
        local_http=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self):
                if self.path=='/health/ready':
                    self.send_response(200);self.send_header('Content-Length','2');self.end_headers();self.wfile.write(b'OK');return
                if self.path!='/metrics': self.send_error(404);return
                req=urllib.request.Request(f'http://127.0.0.1:{php_port}/metrics',headers={'Authorization':self.headers.get('Authorization','')})
                try: response=local_http.open(req,timeout=15)
                except urllib.error.HTTPError as err: response=err
                with response:
                    body=response.read();self.send_response(response.code);self.send_header('Content-Type',response.headers.get('Content-Type','text/plain'));self.end_headers();self.wfile.write(body)
            def do_POST(self):
                if self.path!='/alerts': self.send_error(404);return
                body=json.loads(self.rfile.read(int(self.headers['Content-Length'])));events.append(body);self.send_response(200);self.end_headers();self.wfile.write(b'{}')
        server=None;thread=None;project='ce-monitor-'+secrets.token_hex(4);compose=None;env=None
        try:
            cert=d/'cert.pem';key=d/'key.pem'
            run('openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=host.docker.internal','-addext','subjectAltName=DNS:host.docker.internal','-keyout',str(key),'-out',str(cert),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            server=http.server.ThreadingHTTPServer(('0.0.0.0',https_port),Handler);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);server.socket=tls.wrap_socket(server.socket,server_side=True);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            create_secrets(cfg,token,password,f'https://host.docker.internal:{https_port}/alerts');config.configure(cfg,f'host.docker.internal:{https_port}',cert)
            # Compose file secrets preserve host mode and ownership. CI uses rootful Docker; only these disposable files are chowned.
            for name,uid in [('metrics-token',65534),('alert-webhook',65534),('grafana-password',472)]: run('sudo','chown',str(uid),str(cfg/name))
            # Test accelerated copies, with real rule expressions and real webhook receiver.
            p=json.loads((cfg/'prometheus.json').read_text());p['global']={'scrape_interval':'8s','scrape_timeout':'6s','evaluation_interval':'3s'};(cfg/'prometheus.json').write_text(json.dumps(p))
            a=json.loads((cfg/'alertmanager.json').read_text());a['route'].update(group_wait='1s',group_interval='3s',repeat_interval='1h');(cfg/'alertmanager.json').write_text(json.dumps(a))
            short=d/'forum-test-rules.yml';short.write_text('groups:\n  - name: test\n    rules:\n      - alert: CajeerForumQueueStale\n        expr: cajeer_forum_queue_oldest_age_seconds{queue="extensions"} > 300\n        for: 3s\n        labels: {severity: warning}\n        annotations: {summary: "Test queue delayed"}\n')
            # Override only the forum rule file and inject host-gateway into both services.
            overlay=d/'overlay.json';overlay.write_text(json.dumps({'services':{'prometheus':{'extra_hosts':['host.docker.internal:host-gateway'],'volumes':[str(short)+':/etc/prometheus/rules/forum.yml:ro']},'alertmanager':{'extra_hosts':['host.docker.internal:host-gateway']},'blackbox':{'extra_hosts':['host.docker.internal:host-gateway']}}}))
            env=os.environ|{'CE_MONITOR_CONFIG_DIR':str(cfg),'CE_PROMETHEUS_PORT':str(pp),'CE_ALERTMANAGER_PORT':str(ap),'CE_GRAFANA_PORT':str(gp)}
            compose=['docker','compose','-p',project,'-f',str(HERE/'compose.yaml'),'-f',str(overlay)]
            run(*compose,'config','--quiet',env=env);run(*compose,'up','-d',env=env)
            def get_json(url,headers=None):
                with local_http.open(urllib.request.Request(url,headers=headers or {}),timeout=5) as response:return json.load(response)
            def query(expr):
                return get_json(f'http://127.0.0.1:{pp}/api/v1/query?'+urllib.parse.urlencode({'query':expr}))['data']['result']
            def local_metrics():
                with local_http.open(urllib.request.Request(f'http://127.0.0.1:{php_port}/metrics', headers={'Authorization':'Bearer '+token}), timeout=10) as response:
                    return b'cajeer_forum_queue_items' in response.read()
            wait_until(local_metrics)
            wait_until(lambda: any(x['value'][1]=='1' for x in query('up{job="cajeerengine"}')))
            wait_until(lambda:any(x['value'][1]=='1' for x in query('probe_success{job="forum-tls"}')))
            assert int(float(query('probe_ssl_earliest_cert_expiry{job="forum-tls"}')[0]['value'][1]))>int(time.time())
            assert query('cajeer_forum_queue_items{queue="extensions"}')[0]['value'][1]=='1'
            assert query('cajeer_forum_request_duration_seconds_count{operation="search",outcome="2xx"}')[0]['value'][1]=='1'
            wait_until(lambda:any(e.get('status')=='firing' and any(a['labels']['alertname']=='CajeerForumQueueStale' for a in e['alerts']) for e in events))
            # Restart both monitoring daemons while the alert is firing. Persistent
            # state must survive and the same external receiver must get resolved.
            run(*compose, 'restart', 'prometheus', 'alertmanager', env=env)
            wait_until(lambda:any(x['value'][1]=='1' for x in query('up{job="cajeerengine"}')),seconds=120)
            with sqlite3.connect(db) as con: con.execute("UPDATE ce_forum_extension_events SET state='done'")
            wait_until(lambda:any(e.get('status')=='resolved' and any(a['labels']['alertname']=='CajeerForumQueueStale' for a in e['alerts']) for e in events))
            auth={'Authorization':'Basic '+base64.b64encode(('admin:'+password).encode()).decode()}
            for uid in ['cajeer-forum-http','cajeer-forum-operations']:
                dash=wait_until(lambda:get_json(f'http://127.0.0.1:{gp}/api/dashboards/uid/{uid}',auth))
                assert dash['dashboard']['uid']==uid and len(dash['dashboard']['panels'])>=8
            datasource=get_json(f'http://127.0.0.1:{gp}/api/datasources/uid/cajeer-prometheus',auth);assert datasource['url']=='http://prometheus:9090'
            print('PASS real authenticated TLS scrape, SQL metrics, Grafana provisioning and Alertmanager firing/resolved delivery')
        finally:
            if compose is not None:
                try:
                    with local_http.open(f'http://127.0.0.1:{pp}/api/v1/targets',timeout=5) as response:
                        for target in json.load(response).get('data',{}).get('activeTargets',[]):
                            print('Scrape target:',target.get('labels',{}).get('job'),target.get('health'),target.get('lastError'))
                except (OSError,ValueError,urllib.error.URLError): pass
                subprocess.run([*compose,'logs','--tail','100'],env=env,check=False)
                subprocess.run([*compose,'down','-v','--remove-orphans'],env=env,check=False)
            if server: server.shutdown();server.server_close()
            if thread: thread.join(timeout=5)
            php.terminate();php.wait(timeout=10);php_log.close()
            print((d/'php.log').read_text()[-12000:])


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--integration',action='store_true');args=parser.parse_args()
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(ConfigurationTest))
    if not result.wasSuccessful(): raise SystemExit(1)
    if args.integration: integration()
