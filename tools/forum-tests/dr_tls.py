#!/usr/bin/env python3
"""Test-only TLS ingress to a disposable forum HTTP backend; validates client CA/trust."""
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import http.client
import os
from pathlib import Path
import ssl
import subprocess
import threading


@contextmanager
def tls_ingress(backend_port: int, port: int, directory: Path):
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    key, cert = directory/'tls.key', directory/'tls.crt'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                    '-days', '1', '-subj', '/CN=localhost', '-addext',
                    'subjectAltName=DNS:localhost,IP:127.0.0.1',
                    '-keyout', str(key), '-out', str(cert)],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=20)
    key.chmod(0o600)
    class Proxy(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'
        def do_GET(self): self.forward()
        def do_POST(self): self.forward()
        def do_PUT(self): self.forward()
        def do_PATCH(self): self.forward()
        def do_DELETE(self): self.forward()
        def do_OPTIONS(self): self.forward()
        def forward(self):
            length = int(self.headers.get('Content-Length', '0'))
            if length < 0 or length > 16 * 1024 * 1024 or self.headers.get('Transfer-Encoding'):
                self.send_error(413); return
            body = self.rfile.read(length) if length else None
            headers = {k: v for k, v in self.headers.items() if k.lower() not in
                       ('connection', 'proxy-connection', 'transfer-encoding', 'content-length', 'host')}
            headers['Host'] = f'localhost:{port}'
            headers['X-Forwarded-Proto'] = 'https'
            conn = http.client.HTTPConnection('127.0.0.1', backend_port, timeout=30)
            try:
                conn.request(self.command, self.path, body=body, headers=headers)
                response = conn.getresponse(); payload = response.read()
                self.send_response(response.status)
                for k, v in response.getheaders():
                    if k.lower() not in ('connection', 'transfer-encoding', 'content-length', 'keep-alive'):
                        self.send_header(k, v)
                self.send_header('Content-Length', str(len(payload)))
                self.send_header('Connection', 'close')
                self.end_headers(); self.wfile.write(payload)
            except (OSError, http.client.HTTPException):
                self.send_error(502)
            finally:
                conn.close(); self.close_connection = True
        def log_message(self, *_): pass
    server = ThreadingHTTPServer(('127.0.0.1', port), Proxy)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(str(cert), str(key))
    server.socket = context.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    old = os.environ.get('SSL_CERT_FILE')
    os.environ['SSL_CERT_FILE'] = str(cert)
    thread.start()
    try:
        yield f'https://localhost:{port}', cert
    finally:
        server.shutdown(); server.server_close(); thread.join(timeout=10)
        if old is None: os.environ.pop('SSL_CERT_FILE', None)
        else: os.environ['SSL_CERT_FILE'] = old
