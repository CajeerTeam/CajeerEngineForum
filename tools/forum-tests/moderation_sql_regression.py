#!/usr/bin/env python3
"""SQLite upgrade, real moderation repository SQL, concurrency and rollback."""
import base64, hashlib, json, re, sqlite3, tempfile, time, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda:str(uuid.uuid4())
repo=(ROOT/'core/Forum/Repository/ModerationQueueRepository.php').read_text()
files=(ROOT/'core/Forum/Repository/AttachmentRepository.php').read_text()
reports=(ROOT/'core/Forum/Repository/ReportRepository.php').read_text()
recycle=(ROOT/'core/Forum/Repository/RecycleBinRepository.php').read_text()
def sql(source,prefix):
    return next(s for _,s in re.findall(r"(['\"])([^\n]*?)\1",source) if s.startswith(prefix))
def connect(path):
    db=sqlite3.connect(path,timeout=5);db.execute('PRAGMA foreign_keys=ON');return db
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'live.sqlite';db=connect(path)
    migrations=sorted((ROOT/'migrations/sqlite').glob('*.sql'));migrations=[f for f in migrations if f.name[:4]<='0034'];assert migrations[-1].name=='0034_forum_moderation_center.sql'
    for f in migrations[:-1]:db.executescript(f.read_text())
    author,mod,other,thread,post,legacy,file,submission,report=[uid() for _ in range(9)]
    board='22222222-2222-4222-8222-222222222222';now=int(time.time())
    for u in (author,mod,other):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','test'))
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(thread,board,author,'Original topic'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,1,1,?)',(post,thread,author,'**Original**','markdown'))
    db.execute('INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,?,?,?,?)',(post,'**Original**','markdown',author,'',now))
    db.execute('INSERT INTO ce_forum_reports(id,post_id,user_id,reason) VALUES(?,?,?,?)',(legacy,post,other,'Legacy report'))
    db.commit();db.executescript(migrations[-1].read_text())
    assert db.execute('SELECT review_required FROM ce_forum_boards WHERE id=?',(board,)).fetchone()==(0,)
    assert db.execute('SELECT priority,revision_number,category,snapshot_body FROM ce_forum_reports WHERE id=?',(legacy,)).fetchone()==(1,1,'other',None)
    db.execute(sql(repo,'UPDATE ce_forum_boards SET review_required='),(1,board))
    raw=b'Private review file';encoded=base64.b64encode(raw).decode();digest=hashlib.sha256(raw).hexdigest()
    db.execute(sql(files,'INSERT INTO ce_forum_attachments('),(file,author,'file.txt','text/plain',len(raw),digest,encoded,now-100,now+60))
    payload=json.dumps({'body':'**Pending answer**','body_format':'markdown','attachment_ids':[file]})
    db.execute(sql(repo,'INSERT INTO ce_forum_review_submissions('),(submission,author,board,thread,payload,now,now))
    db.execute(sql(repo,'INSERT INTO ce_forum_review_files('),(file,submission));db.execute(sql(repo,'UPDATE ce_forum_attachments SET expires_at=CASE'),(now+2592000,now+2592000,file))
    event=sql(repo,'INSERT INTO ce_forum_moderation_events(');db.execute(event,(uid(),'submission',submission,author,1,'submit','',now))
    # The queued payload does not consume public ordinals, read sequence, or notification rows.
    assert db.execute('SELECT COUNT(*) FROM ce_forum_live_posts').fetchone()==(1,)
    assert db.execute('SELECT read_sequence FROM ce_forum_threads WHERE id=?',(thread,)).fetchone()==(1,)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_notifications').fetchone()==(0,)
    cleanup=sql(files,'DELETE FROM ce_forum_attachments WHERE id=? AND')
    assert db.execute(cleanup,(file,now+3000000)).rowcount==0
    try:db.execute(sql(repo,'INSERT INTO ce_forum_review_files('),(file,submission))
    except sqlite3.IntegrityError:pass
    else:raise AssertionError('duplicate reservation')
    db.commit()
    # Report creation uses the actual insert-select snapshot; editing the target cannot change it.
    report_sql=sql(reports,'INSERT INTO ce_forum_reports(').replace('$uuid','?')
    db.execute(report_sql,(report,post,mod,'Report reason','abuse',post));db.execute(event,(uid(),'report',report,mod,1,'submit','',now))
    db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('Edited',post))
    assert db.execute('SELECT snapshot_body,snapshot_format FROM ce_forum_reports WHERE id=?',(report,)).fetchone()==('**Original**','markdown')
    db.commit()
    # Compare-and-swap exactly as the repository constructs it; two independent connections cannot overwrite decisions.
    def update(conn,table,set_clause,params,id,expected):
        return conn.execute(f'UPDATE {table} SET {set_clause},revision_number=revision_number+1 WHERE id=? AND revision_number=?',(*params,id,expected)).rowcount
    b=connect(path);assert update(db,'ce_forum_reports','assigned_to=?',(mod,),report,1)==1;db.commit()
    assert update(b,'ce_forum_reports','priority=?',(2,),report,1)==0;b.rollback()
    assert update(b,'ce_forum_reports','priority=?',(2,),report,2)==1;b.commit();b.close()
    assert db.execute('SELECT priority,assigned_to,revision_number FROM ce_forum_reports WHERE id=?',(report,)).fetchone()==(2,mod,3)
    # Approval and publication roll back together, including reservation and receipt.
    published=uid();db.execute('BEGIN');db.execute(sql(repo,'DELETE FROM ce_forum_review_files WHERE submission_id='),(submission,))
    db.execute('UPDATE ce_forum_threads SET read_sequence=2 WHERE id=?',(thread,));db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,body_format) VALUES(?,?,?,?,2,2,?)',(published,thread,author,'**Pending answer**','markdown'))
    bind=sql(files,'UPDATE ce_forum_attachments SET $column=').replace('$column','post_id');assert db.execute(bind,(published,file,author,now)).rowcount==1
    assert update(db,'ce_forum_review_submissions',"status='approved',decided_by=?,decision=?,result_id=?,updated_at=?",(mod,'Approved',published,now),submission,1)==1
    db.rollback();assert db.execute('SELECT status,revision_number,result_id FROM ce_forum_review_submissions WHERE id=?',(submission,)).fetchone()==('pending',1,None)
    assert db.execute('SELECT submission_id FROM ce_forum_review_files WHERE attachment_id=?',(file,)).fetchone()==(submission,)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_live_posts').fetchone()==(1,)
    # Pending submissions prevent automatic purge. Rejected ones release the hold and permit cleanup.
    db.execute('UPDATE ce_forum_threads SET deleted_at=?,deletion_id=?,deleted_by=?,delete_reason=? WHERE id=?',(now-40*86400,uid(),mod,'Test',thread));db.execute("UPDATE ce_forum_reports SET status='resolved' WHERE post_id=?",(post,))
    expiry=re.search(r'\$this->rows\("(SELECT \* FROM \(SELECT \'thread\' AS kind,t.id,t.deleted_at.*?LIMIT 100)"',recycle).group(1)
    assert db.execute(expiry,(now-30*86400,now-30*86400)).fetchall()==[]
    assert update(db,'ce_forum_review_submissions',"status='rejected',decided_by=?,decision=?,updated_at=?",(mod,'Rejected',now),submission,1)==1
    db.execute(sql(repo,'DELETE FROM ce_forum_review_files WHERE submission_id='),(submission,));db.execute(event,(uid(),'submission',submission,mod,2,'reject','Rejected',now))
    assert db.execute(expiry,(now-30*86400,now-30*86400)).fetchone()[:2]==('thread',thread)
    assert db.execute(cleanup,(file,now+3000000)).rowcount==1
    db.commit();backup=sqlite3.connect(Path(tmp)/'backup.sqlite');db.backup(backup);assert backup.execute('SELECT status,decision FROM ce_forum_review_submissions WHERE id=?',(submission,)).fetchone()==('rejected','Rejected');assert backup.execute('SELECT snapshot_body FROM ce_forum_reports WHERE id=?',(report,)).fetchone()==('**Original**',)
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print(f'PASS {len(migrations)} migrations, legacy reports, private queue, file reservation, snapshot, concurrent CAS, atomic rollback, purge protection, release and backup')
