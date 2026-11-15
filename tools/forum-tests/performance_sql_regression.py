#!/usr/bin/env python3
"""Real 0043 upgrade and SQLite planner/seek regression; no PHP PDO required."""
import sqlite3, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
for f in files[:-1]:db.executescript(f.read_text())
board='22222222-2222-4222-8222-222222222222';cat='11111111-1111-4111-8111-111111111111'
hidden=str(uuid.uuid4());db.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',(hidden,cat,'Hidden',''))
user=str(uuid.uuid4());db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,'performance@example.test','Perf','unused'))
# Timestamp ties, soft-deleted content, different ACL scope and deep windows.
rows=[]
for n in range(1800):
    i=str(uuid.UUID(int=n+1));at=f'2026-01-01 00:{n//120:02d}:{(n//40)%3:02d}'
    rows.append((i,hidden if n%13==0 else board,user,f'Topic {n}',at,n%2,1 if n%17==0 else None))
db.executemany('INSERT INTO ce_forum_threads(id,board_id,author_id,title,updated_at,pinned,deleted_at) VALUES(?,?,?,?,?,?,?)',rows)
thread=rows[1][0]
for n in range(1,101):db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,deleted_at) VALUES(?,?,?,?,?,?,?)',(str(uuid.uuid4()),thread,user,'Body',n,n,1 if n%3==0 else None))
db.execute('INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,?)',(user,thread,50));db.commit()
old=db.execute('SELECT id,title,deleted_at FROM ce_forum_threads ORDER BY id').fetchall();gen=db.execute('SELECT name,generation FROM ce_forum_cache_generation ORDER BY name').fetchall()
assert files[-1].name=='0043_forum_performance.sql';db.executescript(files[-1].read_text());db.execute('ANALYZE')
assert old==db.execute('SELECT id,title,deleted_at FROM ce_forum_threads ORDER BY id').fetchall()
assert gen==db.execute('SELECT name,generation FROM ce_forum_cache_generation_effective ORDER BY name').fetchall()
# Exact index ranges used by ThreadRepository.latestAfter; independently verify full walk against OFFSET ordering.
base='SELECT id,updated_at FROM ce_forum_live_threads WHERE board_id=?'
order=' ORDER BY updated_at DESC,id LIMIT '
expected=db.execute(base+' ORDER BY updated_at DESC,id',(board,)).fetchall()
found=[];cursor=None
while True:
    if cursor is None: page=db.execute(base+order+'26',(board,)).fetchall()
    else:
        page=db.execute(base+' AND updated_at=? AND id>?'+order+'26',(board,cursor[1],cursor[0])).fetchall()
        if len(page)<26:page+=db.execute(base+' AND updated_at<?'+order+str(26-len(page)),(board,cursor[1])).fetchall()
    found+=page[:25]
    if len(page)<=25:break
    cursor=page[24]
assert found==expected and len(found)==len(set(found)) and len(found)>1000
# Plan checks use the real view/partial index, not a forced index hint.
plans={
'latest':('SELECT id FROM ce_forum_live_threads WHERE updated_at<? ORDER BY updated_at DESC,id LIMIT 26',('2026-01-01 00:10:00',),'ce_forum_live_latest_idx'),
'board':('SELECT id FROM ce_forum_live_threads WHERE board_id=? ORDER BY pinned DESC,updated_at DESC,id LIMIT 25',(board,),'ce_forum_live_board_idx'),
'author':('SELECT id FROM ce_forum_live_threads WHERE author_id=? ORDER BY updated_at DESC,id LIMIT 25',(user,),'ce_forum_live_author_idx'),
'ordinal':('SELECT id FROM ce_forum_posts WHERE thread_id=? AND deleted_at IS NULL ORDER BY ordinal LIMIT 25',(thread,),'ce_forum_live_ordinal_idx'),
'sequence':('SELECT COUNT(*) FROM ce_forum_posts WHERE thread_id=? AND deleted_at IS NULL AND read_sequence>?',(thread,50),'ce_forum_live_sequence_idx'),
}
for name,(sql,args,index) in plans.items():
    plan=' '.join(str(x) for x in db.execute('EXPLAIN QUERY PLAN '+sql,args))
    assert index in plan,(name,plan);print(name,plan)
# Query from ReadStateRepository: holes are counted, not inferred from watermark arithmetic.
query='SELECT t.id AS thread_id,(SELECT COUNT(*) FROM ce_forum_posts p WHERE p.thread_id=t.id AND p.deleted_at IS NULL AND p.read_sequence>COALESCE(r.read_sequence,0)) AS unread_posts FROM ce_forum_live_threads t LEFT JOIN ce_forum_read_state r ON r.thread_id=t.id AND r.user_id=? WHERE t.id=?'
assert db.execute(query,(user,thread)).fetchone()==(thread,33)
# Current visibility and new cache generations are reflected after deletion/rollback.
db.execute('UPDATE ce_forum_threads SET deleted_at=2 WHERE id=?',(thread,));assert db.execute(query,(user,thread)).fetchone() is None;db.rollback()
assert db.execute(query,(user,thread)).fetchone()==(thread,33)
# Verify the page-first notification projection on the actual schema and a hole in ordinal positions.
actor=str(uuid.uuid4());db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(actor,'actor@example.test','Actor','unused'))
post=db.execute('SELECT id FROM ce_forum_live_posts WHERE thread_id=? AND ordinal=77',(thread,)).fetchone()[0]
event=str(uuid.uuid4());db.execute("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,state) VALUES(?,?,?,?,'reply',1)",(event,user,actor,post))
feed="(SELECT event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind,state,is_read,created_at FROM ce_forum_notifications UNION ALL SELECT event_id,recipient_id,actor_id,post_id,'accepted' AS kind,NULL AS moderation_action,NULL AS subscription_kind,state,is_read,created_at FROM ce_forum_answer_notifications)"
visible=" FROM "+feed+" n JOIN ce_forum_live_posts p ON p.id=n.post_id JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE n.recipient_id=? AND n.state=1 AND (n.kind='moderation' OR NOT EXISTS (SELECT 1 FROM ce_forum_user_blocks b WHERE (b.user_id=n.recipient_id AND b.blocked_id=n.actor_id) OR (b.blocked_id=n.recipient_id AND b.user_id=n.actor_id))) AND t.board_id=?"
projection='SELECT pgrow.*,u.name AS actor_name,(SELECT COUNT(*) FROM ce_forum_posts p2 WHERE p2.thread_id=pgrow.thread_id AND p2.deleted_at IS NULL AND p2.ordinal<pgrow.ordinal) AS preceding FROM (SELECT n.event_id AS id,n.kind,n.moderation_action,n.subscription_kind,n.is_read,n.created_at,n.actor_id,n.post_id,t.id AS thread_id,t.title,p.ordinal'+visible+' ORDER BY n.created_at DESC,n.event_id DESC LIMIT 25 OFFSET 0) pgrow LEFT JOIN ce_users u ON u.id=pgrow.actor_id ORDER BY pgrow.created_at DESC,pgrow.id DESC'
notice=db.execute(projection,(user,board)).fetchone();assert notice[0]==event and notice[-2:] == ('Actor',51)
assert not db.execute(projection,(user,hidden)).fetchall()
db.execute('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES(?,?)',(user,actor));assert not db.execute(projection,(user,board)).fetchall()
# Board aggregate uses only physical posts that belong to live threads.
assert db.execute('SELECT COUNT(*) FROM ce_forum_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE t.board_id=? AND p.deleted_at IS NULL',(board,)).fetchone()[0]==67
assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS 0043 upgrade, five index plans, 1,000+ cursor results, ACL, soft-delete and unread holes')
