#!/usr/bin/env python3
"""Qualification mechanics against a real local TLS server, not a certified forum deployment."""
import http.server
import json
from pathlib import Path
import signal
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
from qualify import Histogram, load, assert_load, Client, BOUNDS

class QualificationTests(unittest.TestCase):
    def test_histogram_and_gate(self):
        h=Histogram()
        for n in range(100000): h.add(20 if n<95000 else 500)
        self.assertEqual(h.percentile(.95),20);self.assertEqual(h.percentile(.99),500)
        self.assertEqual(len(h.counts),len(BOUNDS)+1)
        h.add(70000,'semantic_mismatch');self.assertEqual(h.report()['errors'],1)
        with self.assertRaises(RuntimeError): assert_load({'requests_per_second':20,'operations':{'ssr':h.report()}},1000,.01,10)

    def test_real_tls_paced_load(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self):
                body=b'{"data":{"ok":true}}' if self.path=='/api' else b'<html>marker</html>'
                self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);cert=d/'cert';key=d/'key'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=forum.test','-addext','subjectAltName=DNS:forum.test','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
            t=threading.Thread(target=server.serve_forever,daemon=True);t.start()
            try:
                c=Client('forum.test',cert,server.server_port)
                result=load(c,[('ssr','/forum',{},lambda b:b'marker' in b),('api','/api',{},lambda b:json.loads(b)['data']['ok'])],8,5,40)
                assert_load(result,500,0,25);self.assertGreater(result['requests'],125);self.assertLessEqual(result['requests'],205)
                print('TLS runner:',json.dumps(result),flush=True)
                failed=load(c,[('ssr','/forum',{},lambda b:False)],2,1,5)
                with self.assertRaises(RuntimeError): assert_load(failed,1000,.01,1)
                self.assertGreater(failed['operations']['ssr']['error_types']['semantic_mismatch'],0)
            finally:server.shutdown();server.server_close();t.join()

    def test_supervisor_stale_health_and_timeout(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);php=d/'php';php.write_text('#!/bin/sh\nif [ ! -e '+str(d/'once')+' ]; then touch '+str(d/'once')+'; exit 0; fi\nexec sleep 60\n');php.chmod(0o755)
            # Shorten production bounds in the fixture only; still execute GNU timeout and real signals.
            script=d/'loop.sh';script.write_text((Path(__file__).parent/'loop.sh').read_text().replace('/srv/forum',str(d)).replace('/tmp/ce-loop-',str(d/'ce-loop-')).replace('delay=5','delay=1').replace(':-600',':-1').replace('-ge 30','-ge 1').replace('--kill-after=15s','--kill-after=1s'))
            import os
            proc=subprocess.Popen(['sh',str(script),'worker'],env=os.environ|{'PATH':str(d)+':'+os.environ['PATH']})
            try:
                end=time.monotonic()+5
                while not (d/'ce-loop-health').exists() and time.monotonic()<end:time.sleep(.02)
                self.assertTrue((d/'ce-loop-health').exists())
                end=time.monotonic()+6
                while (d/'ce-loop-health').exists() and time.monotonic()<end:time.sleep(.02)
                self.assertFalse((d/'ce-loop-health').exists());self.assertIsNone(proc.poll())
            finally:
                proc.send_signal(signal.SIGTERM)
                try:proc.wait(timeout=4)
                except subprocess.TimeoutExpired:proc.kill();proc.wait();self.fail('supervisor did not stop')

if __name__=='__main__':unittest.main()
