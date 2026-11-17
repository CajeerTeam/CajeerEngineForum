#!/usr/bin/env python3
"""Fast negative transport tests; physical database restoration is exercised in CI."""
import http.server
import json
from pathlib import Path
import ssl
import tempfile
import threading
import unittest
import urllib.error
import urllib.request

from dr_tls import tls_ingress
from release_acceptance import free_port

class Backend(http.server.BaseHTTPRequestHandler):
    def do_GET(self): self.respond(b'{"status":"ok"}')
    def do_POST(self): self.respond(self.rfile.read(int(self.headers.get('Content-Length','0'))))
    def respond(self, body):
        self.send_response(200)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(body)))
        self.send_header('X-Forwarded-Proto-Test',self.headers.get('X-Forwarded-Proto',''))
        self.end_headers(); self.wfile.write(body)
    def log_message(self,*_): pass

class TLSRegression(unittest.TestCase):
    def test_verified_ingress_and_reject_untrusted(self):
        port=free_port(); public=free_port()
        backend=http.server.ThreadingHTTPServer(('127.0.0.1',port),Backend)
        worker=threading.Thread(target=backend.serve_forever,daemon=True);worker.start()
        try:
            with tempfile.TemporaryDirectory() as tmp:
                with tls_ingress(port,public,Path(tmp)/'tls') as (url,crt):
                    untrusted=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                    with self.assertRaises((ssl.SSLError,urllib.error.URLError)):
                        urllib.request.urlopen(urllib.request.Request(url+'/status'),context=untrusted,timeout=5)
                    trusted=ssl.create_default_context(cafile=str(crt))
                    with urllib.request.urlopen(url+'/health',context=trusted,timeout=5) as response:
                        self.assertEqual(json.load(response),{'status':'ok'})
                        self.assertEqual(response.headers['X-Forwarded-Proto-Test'],'https')
                    request=urllib.request.Request(url+'/write',data=b'payload',method='POST')
                    with urllib.request.urlopen(request,context=trusted,timeout=5) as response:
                        self.assertEqual(response.read(),b'payload')
                    self.assertEqual((Path(tmp)/'tls/tls.key').stat().st_mode & 0o077,0)
        finally:
            backend.shutdown();backend.server_close();worker.join(timeout=5)

if __name__=='__main__': unittest.main()
