#!/usr/bin/env python3
"""Real SQLite upgrade, lifecycle SQL, visibility, rollback and recovery."""
import base64, hashlib, re, sqlite3, tempfile, time, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda: str(uuid.uuid4())
files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0033')
assert files[-1].name=='0033_forum_soft_delete.sql'
repo=(ROOT/'core/Forum/Repository/RecycleBinRepository.php').read_text()
def count(db,table,where='1=1',args=()):return db.execute('SELECT COUNT(*) FROM '+table+' WHERE '+where,args).fetchone()[0]
def sql(prefix):return next(s for _,s in re.findall(r"(['\"])([^\n]*?)\1",repo) if s.startswith(prefix))
with tempfile.TemporaryDirectory() as tmp:
    db=sqlite3.connect(Path(tmp)/'live.db');db.execute('PRAGMA foreign_keys=ON')
    for f in files[:-1]:db.executescript(f.read_text())
    a,b,t,q,p,r,qf,answer,att,report,delivery=[uid() for _ in range(11)];board='22222222-2222-4222-8222-222222222222';now=int(time.time())
    for u in (a,b):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','test'))
    for tid in (t,q):db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,2)',(tid,board,a,'Topic '+tid))
    for pid,tid,author,n in [(p,t,a,1),(r,t,b,2),(qf,q,a,1),(answer,q,b,2)]:
        db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,?,?,?)',(pid,tid,author,'**'+pid+'**',n,n,'markdown'))
        db.execute("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'markdown',?,'',?)",(pid,'**'+pid+'**',author,now))
    db.execute("INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility) VALUES(?,?,1,1,'always')",(t,'Choice'))
    for n in (1,2):db.execute('INSERT INTO ce_forum_poll_options VALUES(?,?,?)',(t,n,str(n)))
    db.execute('INSERT INTO ce_forum_poll_votes VALUES(?,?,?)',(t,2,b))
    db.execute('INSERT INTO ce_forum_questions VALUES(?,?)',(q,answer));db.execute('INSERT INTO ce_forum_answer_votes VALUES(?,?)',(answer,a))
    db.execute("INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,'helpful')",(r,a))
    db.execute('INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,1)',(a,t));db.execute("INSERT INTO ce_forum_watches(thread_id,user_id,mode) VALUES(?,?,'email')",(t,a))
    data=b'attachment';sha=hashlib.sha256(data).hexdigest();db.execute('INSERT INTO ce_forum_attachments(id,owner_id,post_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',(att,b,r,'file.txt','text/plain',len(data),sha,base64.b64encode(data).decode(),now))
    db.execute("INSERT INTO ce_forum_mail_delivery(id,user_id,post_id,board_id,source_kind,mode,due_at) VALUES(?,?,?,?,'thread','email',?)",(delivery,a,r,board,now))
    db.execute("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,state) VALUES(?,?,?,?,'reply',0)",(uid(),a,b,r))
    db.execute('INSERT INTO ce_forum_reports(id,post_id,user_id,reason) VALUES(?,?,?,?)',(report,r,a,'Keep evidence'))
    db.commit();db.executescript(files[-1].read_text());assert count(db,'ce_forum_live_posts')==4
    assert count(db,'ce_forum_post_revisions')==4
    db.executescript((ROOT/'migrations/sqlite/0034_forum_moderation_center.sql').read_text())
    def hide(kind,id,generation,stamp=now):
        table='ce_forum_threads' if kind=='thread' else 'ce_forum_posts'
        assert db.execute('UPDATE '+table+' '+sql(' SET deleted_at=?,deleted_by=').lstrip(),(stamp,a,'Reason',generation,id)).rowcount==1
        filt='post_id IN (SELECT id FROM ce_forum_posts WHERE thread_id=?)' if kind=='thread' else 'post_id=?'
        for table in ['ce_forum_notifications','ce_forum_answer_notifications']:db.execute(f'DELETE FROM {table} WHERE state=0 AND {filt}',(id,))
        db.execute("UPDATE ce_forum_mail_delivery SET state='cancelled',lease_token=NULL,lease_until=0 WHERE state='pending' AND "+filt,(id,))
    def restore(kind,id):
        table='ce_forum_threads' if kind=='thread' else 'ce_forum_posts'
        assert db.execute('UPDATE '+table+' '+sql(' SET deleted_at=NULL,').lstrip(),(id,)).rowcount==1
    old=uid();hide('post',r,old);assert count(db,'ce_forum_live_posts','id=?',(r,))==0
    assert count(db,'ce_forum_attachments','id=?',(att,))==1 and count(db,'ce_forum_post_revisions','post_id=?',(r,))==1
    assert count(db,'ce_forum_notifications','post_id=?',(r,))==0 and db.execute('SELECT state FROM ce_forum_mail_delivery WHERE id=?',(delivery,)).fetchone()==('cancelled',)
    assert count(db,'ce_forum_reactions x JOIN ce_forum_live_posts p ON p.id=x.post_id')==0
    hide('thread',t,uid());assert count(db,'ce_forum_live_threads','id=?',(t,))==0 and count(db,'ce_forum_live_posts','thread_id=?',(t,))==0
    restore('thread',t);assert count(db,'ce_forum_live_posts','thread_id=?',(t,))==1 and count(db,'ce_forum_poll_votes','thread_id=?',(t,))==1
    restore('post',r);assert count(db,'ce_forum_live_posts','thread_id=?',(t,))==2
    hide('post',r,uid(),now-40*86400);assert db.execute('SELECT deletion_id FROM ce_forum_posts WHERE id=?',(r,)).fetchone()[0]!=old
    hide('post',answer,uid());assert db.execute('SELECT q.accepted_post_id,p.id FROM ce_forum_questions q LEFT JOIN ce_forum_live_posts p ON p.id=q.accepted_post_id WHERE q.thread_id=?',(q,)).fetchone()==(answer,None)
    restore('post',answer);assert count(db,'ce_forum_live_posts','id=?',(answer,))==1
    db.commit();backup=sqlite3.connect(Path(tmp)/'backup.db');db.backup(backup);assert backup.execute('SELECT deletion_id FROM ce_forum_posts WHERE id=?',(r,)).fetchone()==db.execute('SELECT deletion_id FROM ce_forum_posts WHERE id=?',(r,)).fetchone();assert count(backup,'ce_forum_live_posts','id=?',(r,))==0
    # extract full double-quoted statement (contains single quoted literals)
    expiry=re.search(r'\$this->rows\("(SELECT \* FROM \(SELECT \'thread\' AS kind,t.id,t.deleted_at.*?LIMIT 100)"',repo).group(1)
    assert db.execute(expiry,(now-30*86400,now-30*86400)).fetchall()==[]
    db.execute("UPDATE ce_forum_reports SET status='resolved' WHERE id=?",(report,));assert db.execute(expiry,(now-30*86400,now-30*86400)).fetchone()[:2]==('post',r)
    db.commit();db.execute('BEGIN');db.execute('DELETE FROM ce_forum_posts WHERE id=? AND deleted_at IS NOT NULL',(r,));assert count(db,'ce_forum_attachments','id=?',(att,))==0;db.rollback();assert count(db,'ce_forum_attachments','id=?',(att,))==1
    db.execute('DELETE FROM ce_forum_posts WHERE id=? AND deleted_at IS NOT NULL',(r,));assert count(db,'ce_forum_post_revisions','post_id=?',(r,))==0 and count(db,'ce_forum_attachments','id=?',(att,))==0
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print(f'PASS {len(files)} migrations, old UUIDs, live views, nested deletion, generation, pending delivery cancellation, accepted answer, open-report retention, backup, rollback and cascade')
