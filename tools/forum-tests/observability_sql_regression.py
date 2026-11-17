#!/usr/bin/env python3
"""Run exact production probe SQL against all real SQLite migrations and a large backlog."""
import json, sqlite3, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
queries=json.loads(subprocess.check_output(['php','-r','require "core/Console/NativeCli.php"; echo json_encode(CajeerEngine\\Observability\\ForumMetrics::queries());'],cwd=ROOT))
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
assert any(f.name=='0048_forum_observability_indexes.sql' for f in files), 'Missing observability migration'
for f in files: db.executescript(f.read_text())
for name,sql in queries.items():
    assert db.execute(sql['count']).fetchone()==(0,),name
    if 'oldest' in sql: assert db.execute(sql['oldest']).fetchone() is None,name
now=int(time.time())
sql="INSERT INTO ce_forum_extension_events(id,event_name,payload,created_at,due_at,state,targets,progress) VALUES(?,?,?,?,?,?,?,?)"
db.executemany(sql,[(f'{i:032x}','forum.thread.create','{"private":"secret"}',now-1000,now-500 if i==0 else now+600,'pending','[]','{}') for i in range(12000)])
db.execute(sql,('f'*32,'forum.thread.create','{}',now,now,'dead','[]','{}'))
assert db.execute(queries['extensions']['count']).fetchone()==(10001,)
assert db.execute(queries['extensions']['oldest']).fetchone()==(now-500,)
assert db.execute(queries['extension_dead']['count']).fetchone()==(1,)
db.execute("UPDATE ce_forum_extension_events SET due_at=? WHERE state='pending'",(now+600,))
assert db.execute(queries['extensions']['oldest']).fetchone()==(now+600,)
for queue,index in [('replicas','ce_forum_replica_state_due'),('moderation','ce_forum_review_status_created'),('reports','ce_forum_report_status_created'),('imports','ce_forum_import_state_updated')]:
    plan=db.execute('EXPLAIN QUERY PLAN '+queries[queue]['oldest']).fetchall()
    assert index in str(plan),(queue,plan)
assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS 0048 observability migration with subsequent migrations, all queue probes, 12000 rows capped, due time, dead letters, four index plans and FK integrity')
