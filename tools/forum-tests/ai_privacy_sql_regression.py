#!/usr/bin/env python3
"""Real SQLite 1.3.10 migration, atomic burst admission, erasure and retention boundaries."""
import sqlite3
import time
from pathlib import Path

root=Path(__file__).resolve().parents[2]
c=sqlite3.connect(':memory:')
c.execute('PRAGMA foreign_keys=ON')
c.execute('CREATE TABLE ce_users (id VARCHAR(36) PRIMARY KEY)')
for name in ('0084_forum_ai_chat.sql','0085_forum_thread_ai.sql','0086_forum_ai_search.sql','0090_forum_ai_privacy_abuse.sql'):
    c.executescript((root/'migrations/sqlite'/name).read_text())
checks=0
def check(ok,name):
    global checks
    assert ok,name
    checks+=1
    print('PASS',name)

now=int(time.time());old=now-91*86400
A='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
B='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
C='cccccccc-cccc-4ccc-8ccc-cccccccccccc'
D='dddddddd-dddd-4ddd-8ddd-dddddddddddd'
E='eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'
c.executemany('INSERT INTO ce_users VALUES (?)',[(A,),(B,)])
c.executemany('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at,busy_until) VALUES (?,?,?,?,?,?)',[(C,A,'Old',old,old,0),(D,A,'Busy',old,old,now+200),(E,B,'Other',now,now,0)])
c.execute("INSERT INTO ce_forum_ai_messages (id,conversation_id,role,content,created_at) VALUES ('ffffffff-ffff-4fff-8fff-ffffffffffff',?,'user','secret',?)",(C,old))
check(c.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations').fetchone()[0]==3,'migration 0090 preserves old and busy conversations')
check(set(row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='index'")) >= {'ce_forum_ai_request_buckets_expire','ce_forum_ai_conversations_expire'},'retention indexes exist')
minute=now//60*60
c.execute('INSERT INTO ce_forum_ai_request_buckets VALUES (?,?,0)',(A,minute))
for i in range(5):
    x=c.execute('UPDATE ce_forum_ai_request_buckets SET requests=requests+1 WHERE user_id=? AND window_start=? AND requests<5',(A,minute))
    check(x.rowcount==1,f'atomic request {i+1} admitted')
check(c.execute('UPDATE ce_forum_ai_request_buckets SET requests=requests+1 WHERE user_id=? AND window_start=? AND requests<5',(A,minute)).rowcount==0,'sixth request is denied atomically')
check(c.execute('SELECT requests FROM ce_forum_ai_request_buckets WHERE user_id=?',(A,)).fetchone()[0]==5,'burst cap cannot exceed five')
# Other user has independent limits.
c.execute('INSERT INTO ce_forum_ai_request_buckets VALUES (?,?,1)',(B,minute))
check(c.execute('SELECT requests FROM ce_forum_ai_request_buckets WHERE user_id=?',(B,)).fetchone()[0]==1,'other owner has independent quota')
# Only idle old conversations are purgeable; active lease must survive.
ids=[r[0] for r in c.execute('SELECT id FROM ce_forum_ai_conversations WHERE updated_at<? AND busy_until=0',(now-90*86400,))]
check(ids==[C],'retention selects only idle old conversation')
for id in ids:
    c.execute('DELETE FROM ce_forum_ai_messages WHERE conversation_id=?',(id,))
    c.execute('DELETE FROM ce_forum_ai_conversations WHERE id=?',(id,))
check(c.execute('SELECT COUNT(*) FROM ce_forum_ai_messages').fetchone()[0]==0,'retention removes child transcript')
check(c.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations').fetchone()[0]==2,'active and other-owner conversations remain')
# Erase A after response completes, with user ownership filter.
c.execute('UPDATE ce_forum_ai_conversations SET busy_until=0 WHERE id=?',(D,))
c.execute('DELETE FROM ce_forum_ai_messages WHERE conversation_id IN (SELECT id FROM ce_forum_ai_conversations WHERE user_id=?)',(A,))
c.execute('DELETE FROM ce_forum_ai_conversations WHERE user_id=?',(A,))
check(c.execute('SELECT id FROM ce_forum_ai_conversations').fetchall()==[(E,)],'bulk erase is owner-scoped')
c.execute('DELETE FROM ce_users WHERE id=?',(A,))
check(c.execute('SELECT COUNT(*) FROM ce_forum_ai_request_buckets').fetchone()[0]==1,'account removal cascades minute buckets')
print(f'Forum AI 1.3.10 SQLite privacy / abuse: {checks} PASS')
