#!/usr/bin/env python3
"""Execute the real target migrations + private conversation/attachment SQL constraints.
This is an offline companion to tools/forum-xenforo-media-integration.php, not a substitute.
"""
import sqlite3
import tempfile
import uuid
import hashlib
import base64
from pathlib import Path
root=Path(__file__).resolve().parents[2]
def uid(): return str(uuid.uuid4())
with tempfile.TemporaryDirectory() as folder:
    con=sqlite3.connect(Path(folder)/'forum.sqlite')
    con.execute('PRAGMA foreign_keys=ON')
    versions=sorted((root/'migrations/sqlite').glob('*.sql'))
    for filename in versions:
        con.executescript(filename.read_text())
    assert len(versions)>=77
    alice,bob, outsider=uid(),uid(),uid()
    for user,name in [(alice,'Alice'),(bob,'Bob'),(outsider,'Other')]:
        con.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,'unused','disabled')",(user,name+'@test.invalid',name))
    conversation,message,file=uid(),uid(),uid()
    job='a'*64
    category=uid()
    con.execute("INSERT INTO ce_forum_categories(id,name,description,position,visibility) VALUES(?,?,?,0,'hidden')",(category,'Import','Private'))
    con.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',
                (job,'2.3.0',category,'test','{}',1,1))
    con.execute('INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,?,?,?)',
                (conversation,alice,'Private legacy','direct',100,100))
    for user in (alice,bob):
        con.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',
                    (conversation,user))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,'')",
                (job,'conversations',90,conversation,'imported'))
    con.execute("INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,body_format,created_at) VALUES(?,?,?,?,?,'plain',?)",
                (message,conversation,alice,1,'Private message',100))
    con.execute("INSERT INTO ce_forum_conversation_revisions(message_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'XenForo import',?)",
                (message,'Private message',alice,100))
    con.execute('UPDATE ce_forum_conversations SET last_sequence=1 WHERE id=?',(conversation,))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,'')",
                (job,'conversation_messages',70,message,'imported'))
    payload=b'hello world!'
    con.commit()
    con.execute('SAVEPOINT importing_file')
    con.execute("CREATE TRIGGER block_private_file BEFORE INSERT ON ce_forum_attachments WHEN NEW.message_id IS NOT NULL BEGIN SELECT RAISE(ABORT,'failure'); END")
    try:
        con.execute('INSERT INTO ce_forum_attachments(id,owner_id,message_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',
                    (file,alice,message,'sample.txt','text/plain',len(payload),hashlib.sha256(payload).hexdigest(),base64.b64encode(payload).decode(),100))
        raise AssertionError('expected rollback')
    except sqlite3.IntegrityError:
        pass
    con.execute('ROLLBACK TO importing_file');con.execute('RELEASE importing_file')
    assert con.execute('SELECT COUNT(*) FROM ce_forum_attachments WHERE message_id=?',(message,)).fetchone()==(0,)
    con.execute('INSERT INTO ce_forum_attachments(id,owner_id,message_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',
                (file,alice,message,'sample.txt','text/plain',len(payload),hashlib.sha256(payload).hexdigest(),base64.b64encode(payload).decode(),100))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,'')",
                (job,'conversation_attachments',43,file,'imported'))
    con.execute("UPDATE ce_forum_conversation_messages SET body=?,body_format='bbcode' WHERE id=?",('[b]Private[/b]',message))
    con.execute("UPDATE ce_forum_conversation_revisions SET body=?,body_format='bbcode' WHERE message_id=? AND revision_number=1",('[b]Private[/b]',message))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,'')",
                (job,'conversation_content',70,message,'imported'))
    con.commit()
    assert con.execute('SELECT COUNT(*) FROM ce_forum_conversation_participants WHERE conversation_id=?',(conversation,)).fetchone()==(2,)
    assert con.execute('SELECT COUNT(*) FROM ce_forum_conversation_participants WHERE conversation_id=? AND user_id=?',(conversation,outsider)).fetchone()==(0,)
    assert con.execute('SELECT COUNT(*) FROM ce_forum_attachments WHERE id=? AND post_id IS NULL AND message_id=?',(file,message)).fetchone()==(1,)
    assert con.execute('SELECT body_format FROM ce_forum_conversation_revisions WHERE message_id=?',(message,)).fetchone()==('bbcode',)
    assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
    assert con.execute('SELECT last_sequence FROM ce_forum_conversations WHERE id=?',(conversation,)).fetchone()==(1,)
    con.close()
print(f'PASS {len(versions)} real SQLite schema migrations, private membership, media FK, revision and transactional rollback')
