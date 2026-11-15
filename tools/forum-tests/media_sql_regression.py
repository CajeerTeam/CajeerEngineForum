#!/usr/bin/env python3
"""Actual raster processor, SQLite upgrade/derivative lifecycle and signed S3 HTTP regression."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import struct
import subprocess
import tempfile
import threading
import time
import uuid
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[2]
uid = lambda: str(uuid.uuid4())
AUTO = r'''spl_autoload_register(function($c){if(str_starts_with($c,'CajeerEngine\\')){$p='core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});'''

def php(code, data=None, env=None):
    return subprocess.check_output(['php','-r',AUTO+code], input=data, cwd=ROOT, env=env, timeout=120)

def png(w,h):
    def chunk(name,data): return struct.pack('>I',len(data))+name+data+struct.pack('>I',zlib.crc32(name+data)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+chunk(b'tEXt',b'Comment\0private-metadata-should-not-survive')+chunk(b'IDAT',zlib.compress((b'\0'+b'\xff\0\0'*w)*h))+chunk(b'IEND',b'')

def digest(data): return hashlib.sha256(data).hexdigest()
raw=png(1200,600); aid=uid(); user=uid(); env=os.environ.copy()
if php('echo function_exists("imagecreatetruecolor")?"yes":"no";')==b'no':
    converter=shutil.which('convert'); assert converter, 'Install GD or ImageMagick for media regression';env['MEDIA_CONVERT_BINARY']=converter
payload=json.dumps({'id':aid,'sha256':digest(raw),'mime':'image/png','data':base64.b64encode(raw).decode()}).encode()
code=r'''$a=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$bytes=base64_decode($a['data']);$v=\CajeerEngine\Forum\Content\ForumImageProcessor::generate($a,$bytes);foreach($v as &$x){if(!\CajeerEngine\Forum\Content\ForumImageProcessor::valid($x,$x['data'])||\CajeerEngine\Forum\Content\ForumImageProcessor::valid($x,$x['data'].'x'))throw new RuntimeException('variant integrity');$x['data']=base64_encode($x['data']);}echo json_encode($v,JSON_THROW_ON_ERROR);'''
variants=json.loads(php(code,payload,env));assert [(v['width'],v['height']) for v in variants.values()]==[(240,120),(960,480),(1200,600)]
for v in variants.values():
    b=base64.b64decode(v['data']);assert digest(b)==v['sha256'] and b'private-metadata-should-not-survive' not in b
print('PASS actual PHP raster processor: 3 sizes, metadata removal, hash and corruption checks')

# Authenticate every request with SigV4, not simply an unsigned mock accepting any PUT.
objects={}; requests=[]; secret=b'forum-test-secret'; access='forum-test-key'
def sign(k,s): return hmac.new(k,s.encode(),hashlib.sha256).digest()
class S3(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def dispatch(self):
        data=self.rfile.read(int(self.headers.get('Content-Length',0)));auth=self.headers.get('Authorization','')
        m=re.fullmatch(r'AWS4-HMAC-SHA256 Credential=([^/]+)/([^,]+), SignedHeaders=([^,]+), Signature=([0-9a-f]+)',auth)
        if not m: self.send_error(403);return
        key,scope,names,signature=m.groups();date,region,service,end=scope.split('/');now=self.headers['x-amz-date'];payload_hash=self.headers['x-amz-content-sha256']
        canonical='\n'.join([self.command,self.path,'',''.join(n+':'+self.headers[n].strip()+'\n' for n in names.split(';')),names,payload_hash]);string='AWS4-HMAC-SHA256\n'+now+'\n'+scope+'\n'+digest(canonical.encode())
        signing=sign(sign(sign(sign(b'AWS4'+secret,date),region),service),end)
        if key!=access or payload_hash!=digest(data) or not hmac.compare_digest(hmac.new(signing,string.encode(),hashlib.sha256).hexdigest(),signature):self.send_error(403);return
        requests.append((self.command,self.path));body=b'';status=200
        if self.command=='PUT':objects[self.path]=data
        elif self.command=='DELETE':objects.pop(self.path,None);status=204
        elif self.path not in objects:status=404
        else:body=objects[self.path]
        self.send_response(status);self.send_header('Content-Length',str(len(body)));self.end_headers()
        if self.command!='HEAD':self.wfile.write(body)
    do_PUT=do_GET=do_DELETE=do_HEAD=dispatch

server=ThreadingHTTPServer(('127.0.0.1',0),S3);threading.Thread(target=server.serve_forever,daemon=True).start()
try:
    env.update(APP_ENV='testing',MEDIA_S3_ALLOW_HTTP='true',FORUM_MEDIA_S3_REPLICA='true',FORUM_MEDIA_S3_PREFIX='regression',S3_ENDPOINT=f'http://127.0.0.1:{server.server_port}',S3_BUCKET='private',S3_REGION='us-east-1',S3_ACCESS_KEY=access,S3_SECRET_KEY=secret.decode(),S3_PATH_STYLE='true')
    result=php(r'''$c=\CajeerEngine\Forum\Service\ForumMediaService::replicaConfig();$d=$c['disk'];$k=$c['prefix'].'/probe';$data='test private bytes';$d->put($k,$data);if($d->getBounded($k,strlen($data))!==$data)throw new RuntimeException('read mismatch');try{$d->getBounded($k,2);throw new LogicException('missing size cap');}catch(RuntimeException $e){}$d->delete($k);if($d->exists($k))throw new RuntimeException('delete failed');echo 'PASS';''',env=env)
    assert result==b'PASS' and not objects and [x[0] for x in requests]==['PUT','GET','GET','DELETE','HEAD']
    print('PASS real PHP S3 SigV4 PUT/GET/DELETE, checksum input, bounded download and missing object')
    with tempfile.TemporaryDirectory(prefix='forum-media-regression-') as tmp:
        path=Path(tmp)/'forum.sqlite';db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON')
        files=sorted((ROOT/'migrations/sqlite').glob('*.sql'));assert files[-1].name=='0030_forum_media_storage.sql'
        for migration in files[:-1]:db.executescript(migration.read_text())
        db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,user+'@example.test','Owner','test'))
        now=int(time.time());db.execute('INSERT INTO ce_forum_attachments(id,owner_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',(aid,user,'old.png','image/png',len(raw),digest(raw),base64.b64encode(raw).decode(),now,now+86400));db.commit()
        db.executescript(files[-1].read_text());assert db.execute('SELECT state FROM ce_forum_media_state WHERE attachment_id=?',(aid,)).fetchone()==('pending',)
        assert db.execute('SELECT file_data FROM ce_forum_attachments WHERE id=?',(aid,)).fetchone()[0]==base64.b64encode(raw).decode()
        repo=(ROOT/'core/Forum/Repository/ForumMediaRepository.php').read_text()
        def sql(prefix):return next(text for _,text in re.findall(r"(['\"])([^\n]*?)\1",repo) if text.startswith(prefix))
        claim=sql('UPDATE ce_forum_media_state SET due_at=')
        assert db.execute(claim,(now+300,'a'*64,aid,now)).rowcount==1 and db.execute(claim,(now+300,'a'*64,aid,now)).rowcount==0
        for name,v in variants.items():db.execute(sql('INSERT INTO ce_forum_media_variants('),(aid,name,v['mime'],v['width'],v['height'],v['size_bytes'],v['sha256'],v['data']))
        db.execute(sql('UPDATE ce_forum_media_state SET state=\'ready\''),(aid,));db.commit()
        backup=sqlite3.connect(':memory:');db.backup(backup);assert backup.execute('SELECT COUNT(*) FROM ce_forum_media_variants').fetchone()==(3,)
        try:db.execute('UPDATE ce_forum_media_variants SET width=1601');raise AssertionError('invalid width accepted')
        except sqlite3.IntegrityError:db.rollback()
        # With PDO available (CI), exercise the actual background service against the signed local S3 server.
        if b'sqlite' in php('echo implode(",",PDO::getAvailableDrivers());'):
            db.commit();env.update(DB_DRIVER='sqlite',SQLITE_DATABASE=str(path),DB_SQLITE_BUSY_TIMEOUT_MS='10000')
            work=r'''$db=new \CajeerEngine\Database\DatabaseManager(new \CajeerEngine\Runtime\ConfigRepository(getcwd()));$tx=new \CajeerEngine\Forum\Service\ForumTransaction($db,new \CajeerEngine\Database\Repository\AuditLogRepository($db));echo $tx->media()->syncReplica();'''
            db.execute("UPDATE ce_forum_media_state SET state='pending' WHERE attachment_id=?",(aid,));db.commit()
            assert php(work.replace('syncReplica()','processImage()'),env=env)==b'ready'
            assert php(work,env=env)==b'ready';assert len(objects)==1 and next(iter(objects.values()))==raw
            role=uid();db.execute('INSERT INTO ce_roles(id,handle,name,permissions) VALUES(?,?,?,?)',(role,'media-test-admin','Test administrator','["*"]'));db.execute('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',(user,role));db.execute("UPDATE ce_forum_attachments SET file_data='corrupt' WHERE id=?",(aid,));db.commit()
            env.update(FORUM_TEST_MEDIA_ID=aid,FORUM_TEST_MEDIA_USER=user)
            restore=work.replace('echo $tx->media()->syncReplica();',r'''$result=$tx->media()->restoreOriginal(new \CajeerEngine\Forum\Security\ForumAccessPolicy(getenv('FORUM_TEST_MEDIA_USER'),['*']),getenv('FORUM_TEST_MEDIA_ID'));echo $result['restored']?'restored':'failed';''')
            assert php(restore,env=env)==b'restored';assert db.execute('SELECT file_data FROM ce_forum_attachments WHERE id=?',(aid,)).fetchone()[0]==base64.b64encode(raw).decode()
            db.execute('UPDATE ce_forum_media_replicas SET due_at=0');db.commit();objects.clear();assert php(work,env=env)==b'ready' and len(objects)==1
            db.execute('DELETE FROM ce_forum_attachments WHERE id=?',(aid,));db.commit();assert php(work,env=env)==b'deleted' and not objects
            assert db.execute('SELECT COUNT(*) FROM ce_forum_media_replicas').fetchone()==(0,)
            print('PASS actual PHP media service: generation, replica commit, verified restore, repair and tombstone deletion')
        else:
            dest='a'*64;key='forum-media/regression/'+aid+'/'+digest(raw)
            db.execute(sql('INSERT INTO ce_forum_media_replicas('),(aid,dest,key));db.commit();db.execute('DELETE FROM ce_forum_attachments WHERE id=?',(aid,));db.commit()
            assert db.execute('SELECT object_key FROM ce_forum_media_replicas').fetchone()==(key,)
            print('SKIP PHP PDO replica integration (driver unavailable); durable tombstone checked with SQLite SQL')
        assert db.execute('SELECT COUNT(*) FROM ce_forum_media_variants').fetchone()==(0,) and db.execute('SELECT COUNT(*) FROM ce_forum_media_state').fetchone()==(0,)
        assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
        print(f'PASS SQLite {len(files)} migrations, legacy image backfill, variants, rollback, backup and cascade')
finally:
    server.shutdown();server.server_close()
