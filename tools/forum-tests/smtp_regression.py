#!/usr/bin/env python3
"""Exercise the actual PHP SMTP transport over a local TCP connection, without vendor."""
import email
import email.policy
import os
import socketserver
import subprocess
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        self.wfile.write(b'220 localhost ESMTP test\r\n')
        while True:
            line=self.rfile.readline(4096)
            if not line: return
            if line.startswith(b'EHLO '): self.wfile.write(b'250-localhost\r\n250 SIZE 1000000\r\n')
            elif line.startswith((b'MAIL FROM:',b'RCPT TO:')): self.wfile.write(b'250 OK\r\n')
            elif line==b'DATA\r\n':
                self.wfile.write(b'354 send message\r\n'); lines=[]
                while True:
                    line=self.rfile.readline(4096)
                    if line==b'.\r\n': break
                    if not line: return
                    lines.append(line)
                if self.server.reject: self.wfile.write(b'451 temporary failure\r\n')
                else:
                    self.server.messages.append(email.message_from_bytes(b''.join(lines),policy=email.policy.default)); self.wfile.write(b'250 accepted\r\n')
            elif line==b'QUIT\r\n': self.wfile.write(b'221 goodbye\r\n'); return
            else: self.wfile.write(b'500 unexpected\r\n')

class Server(socketserver.TCPServer):
    allow_reuse_address=True

with Server(('127.0.0.1',0),Handler) as server:
    server.messages=[];server.reject=False
    worker=threading.Thread(target=server.serve_forever,daemon=True);worker.start()
    env=os.environ.copy();env.update(APP_URL='http://127.0.0.1:8080',FORUM_SMTP_HOST='127.0.0.1',FORUM_SMTP_PORT=str(server.server_address[1]),FORUM_SMTP_SECURITY='plain',FORUM_SMTP_FROM='forum@example.test',FORUM_SMTP_USERNAME='',FORUM_SMTP_PASSWORD='')
    code=r'require "core/Forum/Security/IdentityMail.php"; $m=new CajeerEngine\Forum\Security\IdentityMail();$m->deliver("user@example.test","Forum subscription digest","Two subscription updates\r\nhttps://example.test/forum/watches\r\n","forum-test-123");'
    run=lambda code,environment=env: subprocess.run(['php','-r',code],cwd=ROOT,env=environment,capture_output=True,text=True,timeout=15)
    assert run(code).returncode==0
    msg=server.messages[0];assert msg['To']=='user@example.test' and msg['Message-ID']=='<forum-test-123@localhost>'
    assert 'Two subscription updates' in msg.get_content() and 'https://example.test/forum/watches' in msg.get_content()
    server.reject=True;assert run(code).returncode!=0 and len(server.messages)==1
    server.reject=False;assert run(code).returncode==0 and server.messages[1]['Message-ID']==msg['Message-ID']
    bad=env|{'FORUM_SMTP_USERNAME':'test','FORUM_SMTP_PASSWORD':'secret'};assert run(code,bad).returncode!=0
    verify=r'require "core/Forum/Security/IdentityMail.php";(new CajeerEngine\Forum\Security\IdentityMail())->send("user@example.test","verify",str_repeat("a",64));'
    assert run(verify).returncode==0 and '/forum/verify-email#token=' in server.messages[-1].get_content()
    server.shutdown();worker.join(timeout=5)
print('PASS live PHP SMTP MIME delivery, stable Message-ID, temporary failure, plaintext auth rejection, identity mail compatibility')
