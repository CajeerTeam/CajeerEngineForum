#!/usr/bin/env python3
"""Run the real conversation repository SQL on a disposable SQLite DB, including 0.3.4 upgrade."""
from pathlib import Path
import re
import sqlite3
import tempfile
import concurrent.futures
import uuid

ROOT = Path(__file__).resolve().parents[2]
uid = lambda: str(uuid.uuid4())
files = sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0026')
assert files[-1].name == '0026_forum_conversations.sql'
source = (ROOT/'core/Forum/Repository/ConversationRepository.php').read_text()

def sql(prefix):
    for m in re.finditer(r'\$this->(?:query|row|rows)\(([\'\"])(.*?)\1', source):
        if m.group(2).startswith(prefix):
            return m.group(2)
    raise AssertionError(prefix)

create = sql('INSERT INTO ce_forum_conversations(')
join = sql('INSERT INTO ce_forum_conversation_participants(')
append = sql('INSERT INTO ce_forum_conversation_messages(')
head = sql('UPDATE ce_forum_conversations SET last_sequence=')
mark = sql('UPDATE ce_forum_conversation_participants SET read_sequence=')
member = sql('SELECT c.*,p.joined_sequence')
message = sql('SELECT * FROM ce_forum_conversation_messages WHERE id=')
unread = sql('SELECT COUNT(*) FROM ce_forum_conversation_participants p JOIN')
archive_reset = sql('UPDATE ce_forum_conversation_participants SET archived=0')
leave = sql('UPDATE ce_forum_conversation_participants SET active=0')
rejoin = sql('UPDATE ce_forum_conversation_participants SET joined_sequence=')
edit = sql('UPDATE ce_forum_conversation_messages SET body=')
report = sql('INSERT INTO ce_forum_conversation_reports(')
block = sql('INSERT INTO ce_forum_user_blocks(')
blocked = sql('SELECT user_id FROM ce_forum_user_blocks WHERE')
list_base = re.search(r"\$sql='(SELECT c\.id,c\.title,.*?)';",source).group(1)
search_clause = re.search(r'\$sql\.="( AND \(LOWER\(c\.title\).*?)";',source).group(1)
notification_clause = re.search(r"\$sql\.='( AND p\.muted=0.*?)';else",source).group(1)

def db_at(path):
    db = sqlite3.connect(path, timeout=10)
    db.execute('PRAGMA foreign_keys=ON')
    return db

with tempfile.TemporaryDirectory(prefix='forum-conversation-sql-') as tmp:
    path = Path(tmp)/'forum.sqlite'; db = db_at(path)
    for f in files[:-1]: db.executescript(f.read_text())
    a,b,c,d = [uid() for _ in range(4)]
    for user in (a,b,c,d):
        db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,user+'@example.test','User','test'))
    thread,post=uid(),uid();board='22222222-2222-4222-8222-222222222222'
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(thread,board,a,'Legacy'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,1,1)',(post,thread,a,'Original'))
    db.execute("INSERT INTO ce_forum_watches(thread_id,user_id,mode) VALUES(?,?,'daily')",(thread,b));db.commit()
    db.executescript(files[-1].read_text())
    for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')):
        if f.name[:4]>'0026': db.executescript(f.read_text())
    assert db.execute('SELECT body FROM ce_forum_posts WHERE id=?',(post,)).fetchone()==('Original',)
    assert db.execute('SELECT mode FROM ce_forum_watches').fetchone()==('daily',)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_conversations').fetchone()==(0,)
    cid,first=uid(),uid();now=1000
    db.execute(create,(cid,a,'Private group','group',now,now))
    for user in (a,b): db.execute(join,(cid,user,1,0))
    db.execute(append,(first,cid,a,1,'Old private body',now,'plain'));db.execute(head,(1,now,cid));db.execute(mark,(1,cid,a,1));db.commit()
    assert db.execute(member,(cid,c)).fetchone() is None
    assert db.execute(list_base+' AND p.archived=?',(d,0)).fetchall()==[]
    assert db.execute(unread,(a,)).fetchone()==(0,) and db.execute(unread,(b,)).fetchone()==(1,)
    db.execute(mark,(1,cid,b,1));db.execute(join,(cid,c,2,1));db.commit()
    assert db.execute(message,(first,cid,2)).fetchone() is None
    assert db.execute(list_base+' AND p.archived=?'+search_clause,(c,0,'%old private%','%old private%')).fetchall()==[]
    assert db.execute(list_base+' AND p.archived=?'+search_clause,(b,0,'%old private%','%old private%')) .fetchone() is not None
    db.execute('UPDATE ce_forum_conversation_participants SET muted=1,archived=1 WHERE conversation_id=? AND user_id=?',(cid,c))
    second=uid();db.execute(append,(second,cid,b,2,'New private message',now+1,'markdown'));db.execute(head,(2,now+1,cid));db.execute(archive_reset,(cid,));db.commit()
    assert db.execute(unread,(c,)).fetchone()==(1,)
    assert db.execute(unread+' AND p.muted=0',(c,)).fetchone()==(0,)
    assert db.execute(list_base+notification_clause,(c,)).fetchall()==[]
    assert db.execute('SELECT archived FROM ce_forum_conversation_participants WHERE conversation_id=? AND user_id=?',(cid,c)).fetchone()==(0,)
    db.execute(mark,(2,cid,c,2));db.execute(mark,(1,cid,c,1));assert db.execute(unread,(c,)).fetchone()==(0,)
    rid=uid();db.execute(report,(rid,second,c,'Reason','New private message',now));db.execute(edit,('Edited',None,now+2,second))
    assert db.execute('SELECT body_format FROM ce_forum_conversation_messages WHERE id=?',(second,)).fetchone()==('markdown',)
    assert db.execute('SELECT body_snapshot FROM ce_forum_conversation_reports WHERE id=?',(rid,)).fetchone()==('New private message',)
    db.execute(block,(c,b));assert db.execute(blocked,(b,c,c,b)).fetchone() is not None
    db.execute(leave,(cid,c));assert db.execute(member,(cid,c)).fetchone() is None
    assert db.execute(unread,(c,)).fetchone()==(0,)
    db.execute(rejoin,(3,2,cid,c));assert db.execute(message,(second,cid,3)).fetchone() is None
    db.commit()
    # The same global write reservation as SQLite Repository::lock serializes allocation.
    def send(i):
        connection=db_at(path)
        try:
            connection.execute('BEGIN');connection.execute('UPDATE ce_forum_conversation_lock SET id=id WHERE id=?',(1,))
            last=connection.execute('SELECT last_sequence FROM ce_forum_conversations WHERE id=?',(cid,)).fetchone()[0]
            connection.execute(append,(uid(),cid,a,last+1,'Concurrent '+str(i),now+3,'plain'));connection.execute(head,(last+1,now+3,cid));connection.commit()
        finally: connection.close()
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool: list(pool.map(send,range(20)))
    assert db.execute('SELECT message_sequence FROM ce_forum_conversation_messages WHERE conversation_id=? ORDER BY message_sequence',(cid,)).fetchall()==[(i,) for i in range(1,23)]
    assert db.execute('SELECT last_sequence FROM ce_forum_conversations WHERE id=?',(cid,)).fetchone()==(22,)
    integrity=(ROOT/'core/Forum/ForumIntegrityCheck.php').read_text()
    for name,query in re.findall(r"'((?:invalid_conversation_[a-z_]+)|too_many_conversation_members)'\s*=>\s*'([^']+)'",integrity):
        assert db.execute(query).fetchone()==(0,),name
    assert db.execute(unread,(c,)).fetchone()==(20,)
    db.execute('BEGIN');db.execute(append,(uid(),cid,b,23,'Rollback',now+4,'plain'));db.execute(head,(23,now+4,cid));db.rollback()
    assert db.execute('SELECT last_sequence FROM ce_forum_conversations WHERE id=?',(cid,)).fetchone()==(22,)
    for invalid,args in [(append,(uid(),cid,b,1,'Duplicate',now,'plain')),(block,(b,b)),(join,(cid,b,1,0))]:
        try: db.execute(invalid,args)
        except sqlite3.IntegrityError: pass
        else: raise AssertionError('Missing constraint')
    db.rollback()
    db.execute('DELETE FROM ce_forum_conversations WHERE id=?',(cid,));db.commit()
    for table in ('ce_forum_conversation_participants','ce_forum_conversation_messages','ce_forum_conversation_reports'):
        assert db.execute('SELECT COUNT(*) FROM '+table).fetchone()==(0,)
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
    assert db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    db.close()
    clean=db_at(':memory:')
    for f in files: clean.executescript(f.read_text())
    assert clean.execute('PRAGMA integrity_check').fetchone()==('ok',)
    clean.close()
print(f'PASS {len(files)} SQLite migrations: clean/upgrade, legacy data, membership/history/search, mute/archive, monotonic reads, report snapshot, blocks, rejoin, 20 concurrent allocations, rollback, constraints and cascade')
