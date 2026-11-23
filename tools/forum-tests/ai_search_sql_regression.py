#!/usr/bin/env python3
"""Real SQLite full-schema upgrade, FTS live-source evidence and isolated ephemeral AI sessions."""
import sqlite3
from pathlib import Path

root=Path(__file__).resolve().parents[2]
db=sqlite3.connect(':memory:')
db.row_factory=sqlite3.Row
db.execute('PRAGMA foreign_keys=ON')
checks=0

def check(ok,what):
 global checks
 assert ok,what
 checks+=1

user='1a111111-1111-4111-8111-111111111111'
prior='2a222222-2222-4222-8222-222222222222'
threadconv='3a333333-3333-4333-8333-333333333333'
searchconv='4a444444-4444-4444-8444-444444444444'
thread='5a555555-5555-4555-8555-555555555555'
post='6a666666-6666-4666-8666-666666666666'
for path in sorted((root/'migrations/sqlite').glob('*.sql')):
 if path.name=='0085_forum_thread_ai.sql':
  db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,'ai-search@example.invalid','Search tester','!'))
  db.execute('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)',(prior,user,'Previous generic chat',1,1))
 if path.name=='0086_forum_ai_search.sql':
  db.execute('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at,thread_id) VALUES(?,?,?,?,?,?)',(threadconv,user,'Existing thread chat',2,2,thread))
 db.executescript(path.read_text())
check(db.execute('SELECT purpose FROM ce_forum_ai_conversations WHERE id=?',(prior,)).fetchone()[0]=='chat','existing generic chats preserved')
check(db.execute('SELECT purpose FROM ce_forum_ai_conversations WHERE id=?',(threadconv,)).fetchone()[0]=='thread','existing thread chat purpose backfilled')
db.execute('INSERT INTO ce_forum_ai_conversations(id,user_id,title,created_at,updated_at,purpose) VALUES(?,?,?,?,?,?)',(searchconv,user,'Temporary search',3,3,'search'))
check(db.execute("SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=? AND thread_id IS NULL AND purpose='chat'",(user,)).fetchone()[0]==1,'search sessions excluded from general history')
check(db.execute("SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE user_id=? AND purpose='search'",(user,)).fetchone()[0]==1,'search sessions independently addressable for cleanup')
board=db.execute('SELECT id FROM ce_forum_boards ORDER BY id LIMIT 1').fetchone()[0]
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,user,'Обсуждение SQL индекс'))
db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(post,thread,user,'Индексируемое обсуждение особенного ключевого слова',1,1))
check(db.execute('SELECT COUNT(*) FROM ce_forum_fts_posts WHERE ce_forum_fts_posts MATCH ?',('"индексируемое"',)).fetchone()[0]>0,'actual FTS post candidate indexed')
check(db.execute('SELECT body FROM ce_forum_live_posts WHERE id=?',(post,)).fetchone()[0].startswith('Индексируемое'),'only live post text used for grounding')
db.execute('UPDATE ce_forum_posts SET deleted_at=1000 WHERE id=?',(post,))
check(db.execute('SELECT COUNT(*) FROM ce_forum_live_posts WHERE id=?',(post,)).fetchone()[0]==0,'deleted post cannot be ground truth')
db.execute('DELETE FROM ce_forum_ai_conversations WHERE id=?',(searchconv,))
check(db.execute('SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE purpose=?',('search',)).fetchone()[0]==0,'temporary search history removed')
check(db.execute('PRAGMA foreign_key_check').fetchall()==[],'migration preserves foreign-key integrity')
print(f'Forum AI search SQL: {checks} PASS, {len(list((root/"migrations/sqlite").glob("*.sql")))} migrations')
