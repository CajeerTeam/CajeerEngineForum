#!/usr/bin/env python3
"""Execute real SQLite migration 0087 and transactional ticket invariants, no PHP PDO required."""
import sqlite3
from pathlib import Path

root=Path(__file__).resolve().parents[2]
conn=sqlite3.connect(':memory:')
conn.execute('PRAGMA foreign_keys=ON')
conn.executescript('CREATE TABLE ce_users(id TEXT PRIMARY KEY);')
conn.executescript((root/'migrations/sqlite/0087_forum_ai_support.sql').read_text())
checks=0

def check(ok,name):
    global checks
    if not ok: raise AssertionError(name)
    checks+=1
    print('PASS',name)

for user in ('user-1','user-2'):
    conn.execute('INSERT INTO ce_users(id) VALUES(?)',(user,))
check(len(list(conn.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ce_forum_support_%'")))==3,'ticket/message/FAQ tables exist')
indexes=list(conn.execute("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'ce_forum_support_%'"))
check(len(indexes)>=4,'owner, queue, message and FAQ indexes exist')
conn.execute('INSERT INTO ce_forum_support_faq(id,question,answer,published,sort_order,revision,updated_at) VALUES(?,?,?,?,?,?,?)',('faq-1','Вопрос','Ответ',0,1,1,123))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_support_faq WHERE published=1').fetchone()[0]==0,'unpublished FAQ hidden')
check(conn.execute('UPDATE ce_forum_support_faq SET published=1,revision=revision+1 WHERE id=? AND revision=?',('faq-1',1)).rowcount==1,'FAQ CAS commit')
check(conn.execute('UPDATE ce_forum_support_faq SET question=? WHERE id=? AND revision=?',('stale','faq-1',1)).rowcount==0,'FAQ stale revision refused')
conn.execute('INSERT INTO ce_forum_support_tickets(id,user_id,subject,status,needs_human,revision,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',('t-1','user-1','Вопрос','open',1,1,123,123))
conn.execute('INSERT INTO ce_forum_support_messages(id,ticket_id,role,author_id,body,created_at) VALUES(?,?,?,?,?,?)',('m-1','t-1','user','user-1','Первый вопрос',123))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_support_tickets WHERE user_id=?',('user-2',)).fetchone()[0]==0,'owner filter isolates ticket listing')
with conn:
    claimed=conn.execute('UPDATE ce_forum_support_tickets SET status=?,needs_human=?,revision=revision+1 WHERE id=? AND revision=?',('waiting',0,'t-1',1))
    if claimed.rowcount != 1: raise AssertionError('CAS claim')
    conn.execute('INSERT INTO ce_forum_support_messages(id,ticket_id,role,body,created_at) VALUES(?,?,?,?,?)',('m-2','t-1','assistant','Источник [1]',124))
check(conn.execute('SELECT revision,status FROM ce_forum_support_tickets WHERE id=?',('t-1',)).fetchone()==(2,'waiting'),'AI completion and claim persisted transactionally')
check(conn.execute('UPDATE ce_forum_support_tickets SET status=? WHERE id=? AND revision=?',('resolved','t-1',1)).rowcount==0,'lost update rejected')
try:
    with conn:
        conn.execute('UPDATE ce_forum_support_tickets SET revision=revision+1 WHERE id=?',('t-1',))
        conn.execute('INSERT INTO ce_forum_support_messages(id,ticket_id,role,body,created_at) VALUES(?,?,?,?,?)',('m-2','t-1','operator','Duplicate message',124))
except sqlite3.IntegrityError: pass
check(conn.execute('SELECT revision FROM ce_forum_support_tickets WHERE id=?',('t-1',)).fetchone()[0]==2,'message failure rolls back ticket claim')
conn.execute('DELETE FROM ce_forum_support_tickets WHERE id=?',('t-1',))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_support_messages').fetchone()[0]==0,'ticket delete cascades messages')
conn.execute('INSERT INTO ce_forum_support_tickets(id,user_id,subject,status,needs_human,revision,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',('t-2','user-1','Проблема','open',1,1,125,125))
conn.execute('DELETE FROM ce_users WHERE id=?',('user-1',))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_support_tickets').fetchone()[0]==0,'account deletion cascades member tickets')
print(f'Forum support SQLite SQL: {checks} PASS')
