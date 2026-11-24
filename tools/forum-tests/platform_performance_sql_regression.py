#!/usr/bin/env python3
"""Execute the actual PHP SQL builder on SQLite and compare work, not wall-clock timing."""
import json
import sqlite3
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = sqlite3.connect(':memory:')
DB.execute('PRAGMA foreign_keys=ON')
for migration in sorted((ROOT/'migrations/sqlite').glob('*.sql')):
    DB.executescript(migration.read_text())

# Real schema, real outbox indexes, mixed timestamps and states. The two
# streams interleave and have different distributions; top-N must be exact.
board = DB.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
actor, recipient = '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'
thread = '00000000-0000-4000-8000-000000000003'
for uid in (actor, recipient):
    DB.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(uid,uid+'@test.invalid',uid,'unused'))
DB.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,actor,'Performance fixture'))
posts=[]
for i in range(2000):
    pid=f'00000000-0000-4000-8001-{i+1:012x}'
    posts.append(pid)
    DB.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(pid,thread,actor,'needle text',i+1,i+1))
DB.executemany('INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,state,created_at) VALUES(?,?,?,?,?,?,?)',
    [(f'00000000-0000-4000-8002-{i:012x}',recipient,actor,posts[i%len(posts)],'reply',int(i%7==0),f'2026-01-{1+i%25:02d} 00:00:{i%60:02d}') for i in range(10000)])
DB.executemany('INSERT INTO ce_forum_answer_notifications(event_id,recipient_id,actor_id,post_id,state,created_at) VALUES(?,?,?,?,?,?)',
    [(f'00000000-0000-4000-8003-{i:012x}',recipient,actor,pid,int(i%5==0),f'2026-01-{1+i%25:02d} 00:00:{i%60:02d}') for i,pid in enumerate(posts)])
DB.commit()

code = r'require "core/Console/NativeCli.php"; echo json_encode(\CajeerEngine\Forum\Repository\NotificationRepository::pendingQuery((int)$argv[1]),JSON_THROW_ON_ERROR);'
def actual_pending(n):
    return json.loads(subprocess.check_output(['php','-r',code,str(n)],cwd=ROOT))

def measured(sql, params=()):
    ticks=[0]
    def progress():
        ticks[0]+=1
        return 0
    DB.set_progress_handler(progress,100)
    try:
        return DB.execute(sql,params).fetchall(),ticks[0]*100
    finally:
        DB.set_progress_handler(None,0)

old="SELECT event_id,recipient_id,kind FROM (SELECT event_id,recipient_id,kind,created_at,state FROM ce_forum_notifications UNION ALL SELECT event_id,recipient_id,'accepted' AS kind,created_at,state FROM ce_forum_answer_notifications) n WHERE state=0 ORDER BY created_at,event_id,recipient_id LIMIT "
for n in [0,1,10,100,1000,5000]:
    sql,params=actual_pending(n)
    actual,steps=measured(sql,params)
    expected,old_steps=measured(old+str(max(1,min(1000,n))))
    assert actual==expected,(n,actual[:2],expected[:2])
    if n==100:
        plan=' '.join(str(r) for r in DB.execute('EXPLAIN QUERY PLAN '+sql,params))
        assert 'ce_forum_notify_pending' in plan and 'ce_forum_answer_notify_pending' in plan,plan
        assert steps < old_steps/4,(steps,old_steps)
        print(f'PASS pending merge: 100 identical rows, both indexes, approximately {old_steps} -> {steps} SQLite VM instructions')
print('PASS pending merge: six limits, mixed states, timestamps and source streams')

# Verify the new single-source path against the old ranking expression.
code=r'require "core/Forum/Repository/ForumSearchQuery.php"; echo json_encode(\CajeerEngine\Forum\Repository\ForumSearchQuery::build("sqlite","needle",0,"t.board_id=".$argv[1],null,"posts"),JSON_THROW_ON_ERROR);'
sql,params=json.loads(subprocess.check_output(['php','-r',code,"'"+board+"'"],cwd=ROOT))
assert 'ROW_NUMBER()' not in sql
old_sql=sql.replace('SELECT candidates.*, 1 AS pick FROM candidates','SELECT candidates.*, ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY weight DESC,relevance DESC,post_id,matched_tag) AS pick FROM candidates')
actual,steps=measured(sql,params)
expected,old_steps=measured(old_sql,params)
assert len(actual)==25 and actual==expected
assert steps<old_steps,(steps,old_steps)
print(f'PASS single-source FTS: identical ranked page and count, approximately {old_steps} -> {steps} SQLite VM instructions')
assert DB.execute('PRAGMA foreign_key_check').fetchall()==[]
