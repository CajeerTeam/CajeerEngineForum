#!/usr/bin/env python3
"""Exercise 0029 upgrade, real repository statements and PHP upload validation without vendor."""
import base64
import hashlib
import json
import re
import sqlite3
import subprocess
import tempfile
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
uid = lambda: str(uuid.uuid4())
repo = (ROOT / 'core/Forum/Repository/AttachmentRepository.php').read_text()

def sql(fragment):
    # Use production SQL, not a parallel model of the repository.
    matches = re.findall(r"(['\"])([^\n]*?)\1", repo)
    return next(text for _, text in matches if text.startswith(fragment))

insert = sql('INSERT INTO ce_forum_attachments(')
bind = sql('UPDATE ce_forum_attachments SET $column=')
cleanup = sql('DELETE FROM ce_forum_attachments WHERE id=? AND')
usage = sql('SELECT COUNT(*) AS files,COALESCE(SUM(size_bytes),0)')

with tempfile.TemporaryDirectory(prefix='forum-attachment-') as directory:
    db = sqlite3.connect(Path(directory) / 'live.sqlite')
    db.execute('PRAGMA foreign_keys=ON')
    migrations = sorted((ROOT / 'migrations/sqlite').glob('*.sql'))
    assert migrations[-1].name == '0029_forum_attachments.sql'
    for path in migrations[:-1]:
        db.executescript(path.read_text())
    a,b,t,p,q,c,m = [uid() for _ in range(7)]
    board = '22222222-2222-4222-8222-222222222222'
    for user in (a,b):
        db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)', (user,user+'@test.invalid','Member','test'))
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,2)', (t,board,a,'Legacy topic'))
    for i,post in enumerate((p,q),1):
        db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,?,?,?)', (post,t,a,'**Legacy**',i,i,'markdown'))
    db.execute("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,last_sequence,created_at,updated_at) VALUES(?,?,?,'direct',1,1,1)",(c,a,'Private'))
    for user in (a,b):
        db.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence) VALUES(?,?,1,0)',(c,user))
    db.execute('INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at) VALUES(?,?,?,1,?,1)',(m,c,a,'Old private message'))
    db.commit();db.executescript(migrations[-1].read_text())
    assert db.execute('SELECT body,body_format FROM ce_forum_posts WHERE id=?',(p,)).fetchone()==('**Legacy**','markdown')
    assert db.execute('SELECT COUNT(*) FROM ce_forum_attachments').fetchone()[0]==0
    now=int(time.time());data=b'Attachment regression content\n';digest=hashlib.sha256(data).hexdigest();encoded=base64.b64encode(data).decode()
    def upload(owner,expiry=now+86400):
        aid=uid();db.execute(insert,(aid,owner,'test.txt','text/plain',len(data),digest,encoded,min(now,expiry-1),expiry));return aid
    first=upload(a);second=upload(a);foreign=upload(b);expired=upload(a,now-1)
    db.commit();assert db.execute(usage,(a,)).fetchone()==(3,3*len(data))
    def attach(aid,user,target,kind='post'):
        return db.execute(bind.replace('$column',kind+'_id'),(target,aid,user,now)).rowcount
    assert attach(foreign,a,p)==0
    assert attach(expired,a,p)==0
    assert attach(first,a,p)==1
    assert attach(first,a,q)==0
    assert attach(second,a,m,'message')==1
    db.rollback()
    assert db.execute('SELECT post_id,message_id FROM ce_forum_attachments WHERE id=?',(first,)).fetchone()==(None,None)
    assert attach(first,a,p)==1 and attach(second,a,m,'message')==1;db.commit()
    assert db.execute(cleanup,(first,now)).rowcount==0
    assert db.execute(cleanup,(expired,now)).rowcount==1
    assert db.execute('SELECT expires_at FROM ce_forum_attachments WHERE id=?',(first,)).fetchone()==(0,)
    raw=db.execute('SELECT file_data FROM ce_forum_attachments WHERE id=?',(first,)).fetchone()[0]
    assert hashlib.sha256(base64.b64decode(raw)).hexdigest()==digest
    # Move a post between threads without changing its UUID; bytes follow the post.
    new=uid();db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(new,board,a,'Moved'))
    db.execute('UPDATE ce_forum_posts SET thread_id=?,ordinal=1,read_sequence=1 WHERE id=?',(new,p))
    assert db.execute('SELECT a.id,t.id FROM ce_forum_attachments a JOIN ce_forum_posts p ON p.id=a.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE a.id=?',(first,)).fetchone()==(first,new)
    # Check constraints prevent a public/private double binding.
    try:db.execute('UPDATE ce_forum_attachments SET message_id=? WHERE id=?',(m,first))
    except sqlite3.IntegrityError:pass
    else:raise AssertionError('double binding permitted')
    db.commit();backup=sqlite3.connect(Path(directory)/'backup.sqlite');db.backup(backup)
    assert backup.execute('SELECT file_data FROM ce_forum_attachments WHERE id=?',(first,)).fetchone()==(encoded,)
    db.execute('DELETE FROM ce_forum_posts WHERE id=?',(p,));assert db.execute('SELECT id FROM ce_forum_attachments WHERE id=?',(first,)).fetchone() is None
    db.execute('DELETE FROM ce_forum_conversation_messages WHERE id=?',(m,));assert db.execute('SELECT id FROM ce_forum_attachments WHERE id=?',(second,)).fetchone() is None
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print(f'PASS SQLite {len(migrations)} migrations, legacy body preservation, quota totals, owned single-use binding, rollback, move, expiry, backup and cascade')

php=r'''require 'core/Forum/ForumException.php'; require 'core/Forum/Service/AttachmentService.php';
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);foreach($cases as $c){try{$r=\CajeerEngine\Forum\Service\AttachmentService::validate($c[0],base64_decode($c[1]),$c[2]);if($c[3]!==200||$r['sha256']!==hash('sha256',base64_decode($c[1])))throw new RuntimeException('unexpected acceptance '.$c[0]);}catch(\CajeerEngine\Forum\ForumException $e){if($e->status!==$c[3])throw new RuntimeException('unexpected status '.$c[0].': '.$e->status);}} echo 'PASS '.count($cases).' PHP attachment validation cases';'''
def case(name,data,status,limit=26214400):return [name,base64.b64encode(data).decode(),limit,status]
text=b'Hello forum attachment\n'
cases=[case('report.txt',text,200),case('отчёт.txt',text,200),case('bad.php.txt',text,415),case('bad.svg',b'<svg/>',415),case('file.txt',b'<html><script>alert(1)</script></html>',415),case('../secret.txt',text,422),case('evil\r\n.txt',text,422),case('wrong.png',text,415),case('empty.txt',b'',413),case('large.txt',text,413,3),case('document.pdf',b'%PDF-1.4\n%%EOF\n',200)]
print(subprocess.check_output(['php','-r',php],input=json.dumps(cases).encode(),cwd=ROOT).decode())
