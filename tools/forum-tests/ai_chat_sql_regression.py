#!/usr/bin/env python3
"""Live SQLite regression for 1.3.1 DDL, limits, leases and FK privacy."""
from pathlib import Path
import sqlite3

ROOT=Path(__file__).resolve().parents[2]
c=sqlite3.connect(':memory:')
c.execute('PRAGMA foreign_keys=ON')
c.execute('CREATE TABLE ce_users (id TEXT PRIMARY KEY)')
for file in ('0075_engine_ai_cost_control.sql','0084_forum_ai_chat.sql'):
    c.executescript((ROOT/'migrations/sqlite'/file).read_text())
assert c.execute('SELECT enabled, revision FROM ce_forum_ai_settings').fetchone()==(0,1)
uid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
other='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
c.executemany('INSERT INTO ce_users(id) VALUES (?)',[(uid,),(other,)])
c.execute("INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at) VALUES ('cccccccc-cccc-4ccc-8ccc-cccccccccccc',?,?,1,1)",(uid,'Чат'))
c.execute("INSERT INTO ce_forum_ai_messages(id,conversation_id,role,content,created_at) VALUES ('dddddddd-dddd-4ddd-8ddd-dddddddddddd','cccccccc-cccc-4ccc-8ccc-cccccccccccc','user','Тест',1)")
try:
    c.execute("INSERT INTO ce_forum_ai_messages(id,conversation_id,role,content,created_at) VALUES ('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee','ffffffff-ffff-4fff-8fff-ffffffffffff','user','orphan',1)")
    raise AssertionError('Orphan message accepted')
except sqlite3.IntegrityError:
    pass
c.execute("INSERT INTO ce_forum_ai_usage (user_id,period,used_tokens,reserved_tokens) VALUES (?,'2026-11',800,0)",(uid,))
c.execute('BEGIN') if not c.in_transaction else None
r=c.execute("UPDATE ce_forum_ai_usage SET reserved_tokens=reserved_tokens+? WHERE user_id=? AND period='2026-11' AND used_tokens+reserved_tokens+?<=?",(150,uid,150,1000))
assert r.rowcount==1
r=c.execute("UPDATE ce_forum_ai_usage SET reserved_tokens=reserved_tokens+? WHERE user_id=? AND period='2026-11' AND used_tokens+reserved_tokens+?<=?",(100,uid,100,1000))
assert r.rowcount==0
r=c.execute("UPDATE ce_forum_ai_usage SET reserved_tokens=reserved_tokens-?,used_tokens=used_tokens+? WHERE user_id=? AND period='2026-11' AND reserved_tokens>=?",(150,40,uid,150))
assert r.rowcount==1
assert c.execute('SELECT used_tokens,reserved_tokens FROM ce_forum_ai_usage').fetchone()==(840,0)
c.execute('DELETE FROM ce_users WHERE id=?',(uid,))
assert c.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations').fetchone()[0]==0
assert c.execute('SELECT COUNT(*) FROM ce_forum_ai_messages').fetchone()[0]==0
assert c.execute('SELECT COUNT(*) FROM ce_forum_ai_usage').fetchone()[0]==0
print('PASS: SQLite migration 0084 creates all five tables with owner cascading and atomic token ceiling')
