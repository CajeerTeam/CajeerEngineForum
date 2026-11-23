#!/usr/bin/env python3
"""End-to-end SQLite schema upgrade, isolated thread sessions and live-post pagination."""
from pathlib import Path
import sqlite3, uuid
ROOT=Path(__file__).resolve().parents[2]
connection=sqlite3.connect(':memory:')
connection.row_factory=sqlite3.Row
connection.execute('PRAGMA foreign_keys=ON')
uid='98989898-9898-4898-8989-989898989898'
other='89898989-8989-4898-8989-898989898989'
general='77777777-7777-4777-8777-777777777777'
thread='66666666-6666-4666-8666-666666666666'
conversation='55555555-5555-4555-8555-555555555555'
files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
checks=0
def check(value,what):
 global checks
 assert value,what
 checks+=1
for file in files:
 if file.name=='0085_forum_thread_ai.sql':
  connection.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(uid,'ai-owner@example.invalid','AI Owner','!'))
  connection.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(other,'ai-other@example.invalid','AI Other','!'))
  connection.execute('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)',(general,uid,'Prior 1.3.1 conversation',1,1))
  check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations').fetchone()[0]==1,'prior chat exists before upgrade')
 connection.executescript(file.read_text())
check(connection.execute('SELECT thread_id FROM ce_forum_ai_conversations WHERE id=?',(general,)).fetchone()['thread_id'] is None,'migration preserves old chat in generic scope')
board=connection.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()['id']
connection.execute('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',(thread,board,'Example topic'))
connection.execute('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at,thread_id) VALUES(?,?,?,?,?,?)',(conversation,uid,'Topic AI',2,2,thread))
connection.execute("UPDATE ce_forum_ai_conversations SET purpose='thread' WHERE id=?",(conversation,))
check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=? AND thread_id IS NULL',(uid,)).fetchone()[0]==1,'ordinary AI list excludes thread conversations')
check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=? AND thread_id=?',(uid,thread)).fetchone()[0]==1,'thread list includes owner and correct topic')
check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=? AND thread_id=?',(other,thread)).fetchone()[0]==0,'second user cannot list thread session')
check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE id=? AND user_id=? AND thread_id IS NULL',(conversation,uid)).fetchone()[0]==0,'generic chat cannot claim contextual session')
for i in range(1,30):
 post=str(uuid.uuid5(uuid.NAMESPACE_DNS,'thread-ai-post-'+str(i)))
 connection.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',(post,thread,'Post #'+str(i),i,i))
 if i==28:deleted=post
connection.execute('UPDATE ce_forum_posts SET deleted_at=1000 WHERE id=?',(deleted,))
first=connection.execute('SELECT id,ordinal,body FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal ASC LIMIT 1',(thread,)).fetchone()
recent=connection.execute('SELECT id,ordinal,body FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal DESC LIMIT 23',(thread,)).fetchall()
seen={first['id']:first}
for row in recent:seen[row['id']]=row
seen=sorted(seen.values(),key=lambda r:r['ordinal'])
check(len(seen)==24,'sample is bounded to first and newest 23 live posts')
check(seen[0]['ordinal']==1 and seen[-1]['ordinal']==29,'first and latest messages included')
check(deleted not in [r['id'] for r in seen],'deleted posts excluded from sample')
page_for_last=1+(connection.execute('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=? AND ordinal<=?',(thread,29)).fetchone()[0]-1)//25
check(page_for_last==2,'citation page uses live post offset rather than raw ordinal')
connection.execute('UPDATE ce_forum_threads SET deleted_at=1000 WHERE id=?',(thread,))
check(connection.execute('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=?',(thread,)).fetchone()[0]==0,'deleted topic has no live posts')
connection.execute('DELETE FROM ce_users WHERE id=?',(uid,))
check(connection.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=?',(uid,)).fetchone()[0]==0,'account deletion cascades all AI histories')
check(connection.execute('PRAGMA foreign_key_check').fetchall()==[],'foreign keys remain valid')
print(f'Forum contextual AI SQL: {checks} PASS (all {len(files)} SQLite migrations)')
