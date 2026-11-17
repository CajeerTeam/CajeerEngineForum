#!/usr/bin/env python3
"""Fail-closed regression tests for CI job aggregation, signed-release binding and host gates."""
import copy
import importlib.util
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch
import ssl
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('evidence', ROOT/'tools/certification-evidence.py')
e = importlib.util.module_from_spec(spec); spec.loader.exec_module(e)
a = e.a
ci = e.ci
spec_remote = importlib.util.spec_from_file_location('remote', ROOT/'tools/verify-certified-run.py')
remote = importlib.util.module_from_spec(spec_remote); spec_remote.loader.exec_module(remote)
COMMIT = 'a'*40
REPO = 'CajeerTeam/CajeerEngineForum'
RUN = 120
ATTEMPT = 2
VERSION = '0.9.6'


def jobs():
    rows=[]
    for name,count in ci.EXPECTED.items():
        for i in range(count):
            rows.append({'id':len(rows)+1, 'name':f'{name} ({i})' if count>1 else name, 'conclusion':'success',
                         'html_url':'https://github.com/example/actions/jobs/'+str(len(rows)+1),
                         'started_at':'2026-11-16T20:00:00Z', 'completed_at':'2026-11-16T20:01:00Z',
                         'steps':[{'name':'Acceptance','conclusion':'success'}]})
    rows.sort(key=lambda j:(j['name'],j['id']))
    return {'schema':1,'ok':True,'repository':REPO,'run_id':RUN,'attempt':ATTEMPT,
            'commit':COMMIT,'event':'push','head_sha':COMMIT,'ref':'refs/tags/v0.9.6',
            'tagged_release':True, 'needs':{name:'success' for name in ci.EXPECTED},'jobs':rows}


def fixture(directory):
    for mode in ('source','dist'):
        base=f'cajeerengine-{VERSION}-{mode}'
        (directory/(base+'.zip')).write_bytes(mode.encode())
        (directory/(base+'.zip.sha256')).write_bytes(b'dummy')
        (directory/(base+'.zip.sig')).write_bytes(b'dummy')
        (directory/(base+'.manifest.json')).write_bytes(b'{}')
    for name in ('ci-release.pub',f'cajeerengine-{VERSION}-artifacts.json'):
        (directory/name).write_bytes(b'dummy')
    report={'schema':1,'ok':True,'version':VERSION,'profile':'nginx-tls-fpm-pgsql',
            'global_rate_limit':10000,'auth_rate_limit':20,
            'artifact_sha256':a.sha(directory/f'cajeerengine-{VERSION}-dist.zip'),
            'checks':dict.fromkeys(a.SECURITY,True),
            'load':{'requests':400,'workers':8,'errors':0,'requests_per_second':100,'p95_ms':30,
                    'routes':{r:{'requests':100,'errors':0,'p95_ms':30} for r in a.ROUTES}}}
    a.write_report(directory/'production-acceptance.json',report)
    a.write_report(directory/'dependency-audit.json',{'advisories':[],'abandoned':[]})
    a.write_report(directory/'ci-jobs.json',jobs())
    for label,driver,transport in (('sqlite','sqlite','file'),('pg17','pgsql','file'),
                                   ('pg18','pgsql','file'),('s3','sqlite','s3')):
        a.write_report(directory/('dr-'+label+'.json'),{
            'schema':1,'ok':True,'version':VERSION,'commit':COMMIT,
            'artifact_sha256':a.sha(directory/f'cajeerengine-{VERSION}-dist.zip'),
            'transport':transport,'driver':driver,'checks':dict.fromkeys(e.DR_CHECKS,True),
            'load':{'reads':50,'writes':4,'busy':1},
            'service_rto_seconds':34.5,'rpo_upper_bound_seconds':50})
    with patch.object(e,'verify_signatures'):
        e.seal(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)
    return report


class EvidenceTests(unittest.TestCase):
    def test_valid_and_bound_to_exact_commit_run_and_bytes(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp); fixture(directory)
            with patch.object(e,'verify_signatures'):
                e.verify(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)
                for args in ((VERSION,'b'*40,REPO,RUN,ATTEMPT), (VERSION,COMMIT,REPO,RUN+1,ATTEMPT),
                             (VERSION,COMMIT,REPO,RUN,ATTEMPT+1)):
                    with self.assertRaises(ValueError): e.verify(directory,*args)
                (directory/f'cajeerengine-{VERSION}-dist.zip.sig').write_bytes(b'altered')
                with self.assertRaises(ValueError): e.verify(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)

    def test_reject_missing_bad_host_and_audit(self):
        changes=[('host ACL', 'production-acceptance.json', lambda r:r['checks'].pop('rate_limit')),
                 ('host errors', 'production-acceptance.json', lambda r:r['load'].update(errors=1)),
                 ('host latency', 'production-acceptance.json', lambda r:r['load']['routes']['page'].update(p95_ms=1001)),
                 ('host digest', 'production-acceptance.json', lambda r:r.update(artifact_sha256='f'*64)),
                 ('advisory', 'dependency-audit.json', lambda r:r.update(advisories={'a/b':[{'id':'CVE'}]})),
                 ('abandoned', 'dependency-audit.json', lambda r:r.update(abandoned={'a/b':'other'})),
                 ('suppressed severity','dependency-audit.json',lambda r:r.update(**{'ignored-severity':['high']})),
                 ('bad CI conclusion','ci-jobs.json',lambda r:r['jobs'][0].update(conclusion='failure')),
                 ('missing CI matrix','ci-jobs.json',lambda r:r['jobs'].pop()),
                 ('wrong CI ref','ci-jobs.json',lambda r:r.update(ref='refs/heads/main')),
                 ('DR report failed','dr-sqlite.json',lambda r:r.update(ok=False)),
                 ('DR bad dist','dr-pg17.json',lambda r:r.update(artifact_sha256='a'*64)),
                 ('DR missing ACL','dr-pg18.json',lambda r:r['checks'].pop('https_acl_media_search_identity_write')),
                 ('DR wrong transport','dr-s3.json',lambda r:r.update(transport='file')),
                 ('DR missing HTTP load','dr-s3.json',lambda r:r['load'].update(reads=0)),
                 ('DR RTO over budget','dr-s3.json',lambda r:r.update(service_rto_seconds=1900)),
                 ('DR RPO over budget','dr-pg18.json',lambda r:r.update(rpo_upper_bound_seconds=3601))]
        for name,filename,mutate in changes:
            with self.subTest(name=name),tempfile.TemporaryDirectory() as tmp:
                directory=Path(tmp); fixture(directory)
                data=json.loads((directory/filename).read_text()); mutate(data)
                a.write_report(directory/filename,data)
                with patch.object(e,'verify_signatures'),self.assertRaises(ValueError):
                    e.seal(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)

    def test_signed_verification_is_mandatory(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp); fixture(directory)
            with self.assertRaises((ValueError,subprocess.CalledProcessError)):
                e.verify(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)
            with patch.object(e,'verify_signatures') as checked:
                e.verify(directory,VERSION,COMMIT,REPO,RUN,ATTEMPT)
                checked.assert_called_once()

    def test_ci_jobs_and_remote_provenance(self):
        report=jobs()
        needs={name:{'result':'success'} for name in ci.EXPECTED}
        self.assertTrue(ci.validate_report(report,COMMIT,RUN,ATTEMPT,REPO,needs))
        falsified=copy.deepcopy(report); falsified['jobs'][0]['conclusion']='skipped'
        with self.assertRaises(ValueError):ci.validate_report(falsified,COMMIT,RUN,ATTEMPT,REPO,needs)
        falsified=copy.deepcopy(report); falsified['jobs'][0]['steps'][0]['conclusion']='failure'
        with self.assertRaises(ValueError):ci.validate_report(falsified,COMMIT,RUN,ATTEMPT,REPO,needs)
        falsified=copy.deepcopy(report); falsified['needs']['postgres']='skipped'
        with self.assertRaises(ValueError):ci.validate_report(falsified,COMMIT,RUN,ATTEMPT,REPO,needs)
        run={'id':RUN,'run_attempt':ATTEMPT,'head_sha':COMMIT,'status':'completed',
             'conclusion':'success','event':'push','head_branch':'v0.9.6'}
        items=report['jobs']+[{'id':200,'name':'certification','conclusion':'success'},
                              {'id':201,'name':'forum-required','conclusion':'success'}]
        def request(path):return run if path.endswith('/actions/runs/'+str(RUN)) else {'jobs':items}
        self.assertEqual(remote.verify(report,REPO,'v0.9.6',COMMIT,request),len(report['jobs']))
        broken=copy.deepcopy(run);broken['conclusion']='failure'
        with self.assertRaises(ValueError):
            remote.verify(report,REPO,'v0.9.6',COMMIT,lambda p:broken if 'jobs?' not in p else {'jobs':items})
        broken_items=copy.deepcopy(items);broken_items[-1]['conclusion']='failure'
        with self.assertRaises(ValueError):
            remote.verify(report,REPO,'v0.9.6',COMMIT,lambda p:run if 'jobs?' not in p else {'jobs':broken_items})

    def test_real_tls_load_transport_and_semantic_failure(self):
        # This is a test of the load generator, not a CMS benchmark. No production certificate is emitted.
        class Handler(BaseHTTPRequestHandler):
            protocol_version='HTTP/1.1'
            def do_GET(self):
                body=b'valid' if self.path!='/wrong' else b'wrong'; self.send_response(200); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
            def log_message(self,*args): pass
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp); cert=d/'cert.pem'; key=d/'key.pem'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=acceptance.invalid','-addext','subjectAltName=DNS:acceptance.invalid','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            server=ThreadingHTTPServer(('127.0.0.1',0),Handler); ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                client=a.Client('acceptance.invalid',cert,server.server_port)
                routes={r:('/ok',{},lambda b:b==b'valid') for r in a.ROUTES}
                result=a.load(client,routes)
                self.assertTrue(a.load_pass(result),result)
                print('TLS harness fixture:',json.dumps(result),flush=True)
                routes['page']=('/wrong',{},lambda b:b==b'valid')
                result=a.load(client,routes,count=16,workers=4)
                self.assertEqual(result['errors'],4); self.assertEqual(result['error_types'],{'semantic mismatch':4})
                with self.assertRaises(ssl.SSLCertVerificationError): a.Client('wrong.invalid',cert,server.server_port).request('/ok')
            finally: server.shutdown(); server.server_close(); thread.join()

if __name__=='__main__': unittest.main(verbosity=2)
