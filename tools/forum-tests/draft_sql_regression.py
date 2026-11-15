#!/usr/bin/env python3
"""SQLite migration and actual draft/revision repository SQL, without a PHP PDO dependency."""
import json, re, sqlite3, tempfile, uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda:str(uuid.uuid4())
source=(ROOT/'core/Forum/Repository/DraftRepository.php').read_text()
def sql(prefix):
    return next(s for _,s in re.findall(r"(['\"])([^\n]*?)\1",source) if s.startswith(prefix))
def connect(path):
    db=sqlite3.connect(path,timeout=20);db.execute('PRAGMA foreign_keys=ON');return db
with tempfile.TemporaryDirectory(prefix='forum-drafts-') as temp:
    path=Path(temp)/'db.sqlite';db=connect(path);files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0032');assert files[-1].name=='0032_forum_drafts_revisions.sql'
    for f in files[:-1]:db.executescript(f.read_text())
    a,b,t,p,c,m,d,attachment=[uid() for _ in range(8)];board='22222222-2222-4222-8222-222222222222'
    for user in (a,b):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,user+'@example.test','Member','test'))
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(t,board,a,'Existing'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,1,1,?)',(p,t,a,'**old**','markdown'))
    db.execute("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,last_sequence,created_at,updated_at) VALUES(?,?,?,'direct',1,1,1)",(c,a,'Private'))
    for u in (a,b):db.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence) VALUES(?,?,1,0)',(c,u))
    db.execute('INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at) VALUES(?,?,?,1,?,1)',(m,c,a,'Private old'))
    db.commit();db.executescript(files[-1].read_text())
    assert db.execute('SELECT body,body_format,revision_number FROM ce_forum_post_revisions WHERE post_id=?',(p,)).fetchone()==('**old**','markdown',1)
    assert db.execute('SELECT body FROM ce_forum_conversation_revisions WHERE message_id=?',(m,)).fetchone()==('Private old',)
    ins=sql('INSERT INTO ce_forum_drafts(');save=sql('UPDATE ce_forum_drafts SET payload=?,revision_number=');complete=sql("UPDATE ce_forum_drafts SET payload='{}'");find=sql('SELECT * FROM ce_forum_drafts WHERE id=');delete=sql('DELETE FROM ce_forum_drafts WHERE id=? AND user_id=? AND revision_number=');extend=sql('UPDATE ce_forum_attachments SET expires_at=')
    payload=json.dumps({'body':'draft','body_format':'markdown','attachment_ids':[]});db.execute(ins,(d,a,'reply',t,payload,100,200));assert db.execute(find,(d,b)).fetchone() is None
    # Same UUID in another account is independent, cannot overwrite the first.
    db.execute(ins,(d,b,'new_conversation',None,payload,100,200));assert db.execute(save,('changed',101,201,d,b,1)).rowcount==1;assert db.execute(find,(d,a)).fetchone()[5]==payload;db.commit()
    def attempt(body):
        with connect(path) as x:return x.execute(save,(json.dumps({'body':body}),102,202,d,a,1)).rowcount
    with ThreadPoolExecutor(2) as pool:assert sorted(pool.map(attempt,['device 1','device 2']))==[0,1]
    assert db.execute('SELECT revision_number FROM ce_forum_drafts WHERE user_id=? AND id=?',(a,d)).fetchone()==(2,)
    assert db.execute(save,('stale',103,203,d,a,1)).rowcount==0
    # Publication result and a new post roll back together; retry cannot consume an already completed row.
    published=uid();db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,2,2)',(published,t,a,'Published'))
    result=json.dumps({'id':published});assert db.execute(complete,(result,104,204,d,a,2)).rowcount==1;db.rollback()
    assert db.execute('SELECT id FROM ce_forum_posts WHERE id=?',(published,)).fetchone() is None
    assert db.execute(complete,(result,104,204,d,a,2)).rowcount==1;db.commit();assert db.execute(complete,(result,105,205,d,a,2)).rowcount==0;assert db.execute(delete,(d,a,2)).rowcount==0;assert db.execute(find,(d,a)).fetchone()[6]==result
    # Pending attachment lease extends only for its owner and cannot shorten a longer lease.
    db.execute('INSERT INTO ce_forum_attachments(id,owner_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',(attachment,a,'text.txt','text/plain',3,'0'*64,'YWJj',100,200))
    assert db.execute(extend,(300,300,attachment,b)).rowcount==0;db.execute(extend,(300,300,attachment,a));db.execute(extend,(250,250,attachment,a));assert db.execute('SELECT expires_at FROM ce_forum_attachments WHERE id=?',(attachment,)).fetchone()==(300,)
    # Actual dynamic SQL in RevisionRepository: expand fixed kind names, then run the CAS.
    rs=(ROOT/'core/Forum/Repository/RevisionRepository.php').read_text();snap=re.search(r'"(INSERT INTO \$table\(\$key,revision_number,body,body_format,editor_id,edit_reason,created_at\) VALUES\(\?,\?,\?,\?,\?,\?,\?\))"',rs).group(1).replace('$table','ce_forum_post_revisions').replace('$key','post_id');cas=re.search(r'"(UPDATE \$parent SET revision_number=\? WHERE id=\? AND revision_number=\?)"',rs).group(1).replace('$parent','ce_forum_posts')
    db.execute(snap,(p,2,'new text','plain',a,'edit',101));assert db.execute(cas,(2,p,1)).rowcount==1;db.execute('UPDATE ce_forum_posts SET body=?,body_format=? WHERE id=?',('new text','plain',p));db.commit()
    assert db.execute(cas,(3,p,1)).rowcount==0;db.execute(snap,(p,3,'**old**','markdown',a,'restore',102));db.execute(cas,(3,p,2));db.execute('UPDATE ce_forum_posts SET body=?,body_format=? WHERE id=?',('**old**','markdown',p));db.commit()
    assert db.execute('SELECT revision_number,body FROM ce_forum_post_revisions WHERE post_id=? ORDER BY revision_number',(p,)).fetchall()==[(1,'**old**'),(2,'new text'),(3,'**old**')]
    db.execute(snap,(p,4,'bad','plain',a,'rollback',103));db.execute(cas,(4,p,3));db.rollback();assert db.execute('SELECT revision_number FROM ce_forum_posts WHERE id=?',(p,)).fetchone()==(3,)
    checks=(ROOT/'core/Forum/ForumIntegrityCheck.php').read_text();checks=dict((k,s) for k,_,s in re.findall(r"^\s*'([a-z_]+)'=>\s*(['\"])(.*?)\2,\s*$",checks,re.M) if k in ('invalid_post_revision','invalid_message_revision','invalid_draft_expiry'))
    assert len(checks)==3 and all(db.execute(q).fetchone()==(0,) for q in checks.values());db.execute('DELETE FROM ce_forum_post_revisions WHERE post_id=? AND revision_number=2',(p,));assert db.execute(checks['invalid_post_revision']).fetchone()==(1,);db.rollback()
    db.execute('DELETE FROM ce_forum_posts WHERE id=?',(p,));assert db.execute('SELECT COUNT(*) FROM ce_forum_post_revisions WHERE post_id=?',(p,)).fetchone()==(0,);db.commit()
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print('PASS SQLite 0032 upgrade, initial revisions, ownership, concurrent CAS, atomic receipt, attachment expiry, restore, rollback, corruption and cascade')
