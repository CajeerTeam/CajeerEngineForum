#!/usr/bin/env python3
"""Real SQLite fixture: prove 0082 dashboard reads match ledger / rollup SQL.
The PHP service itself is exercised by forum-analytics-dashboard-regression.php
with pdo_sqlite in CI; this test works in Python-only environments too.
"""
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
root=Path(__file__).resolve().parents[2]
con=sqlite3.connect(':memory:')
con.executescript((root/'migrations/sqlite/0081_forum_analytics_events.sql').read_text())
con.executescript((root/'migrations/sqlite/0082_forum_analytics_rollup.sql').read_text())
def epoch(date):return int(datetime.strptime(date,'%Y-%m-%d').replace(tzinfo=timezone.utc).timestamp())
for name,date,count in [('forum_thread_view','2026-11-22',4),('forum_thread_created','2026-11-22',3),('forum_post_published','2026-11-23',2),('forum_thread_view','2026-11-20',2)]:
 con.execute('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?)',(epoch(date),name,count,epoch('2026-11-23')))
for num,date,name in [(1,'2026-11-21','forum_thread_created'),(2,'2026-11-22','forum_thread_view'),(3,'2026-11-23','forum_thread_created'),(4,'2026-11-23','forum_thread_created')]:
 con.execute('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)',(f'{num:032x}',name,epoch(date)+5))
def grouped(a,b):
 applied=con.execute('SELECT day,event_name,total FROM ce_forum_analytics_daily WHERE day>=? AND day<?',(epoch(a),epoch(b))).fetchall()
 pending=con.execute('SELECT created_at-(created_at % 86400) AS day,event_name,COUNT(*) FROM ce_forum_analytics_events WHERE aggregated_at IS NULL AND created_at>=? AND created_at<? GROUP BY event_name,created_at-(created_at % 86400)',(epoch(a),epoch(b))).fetchall()
 m={}
 for day,name,n in applied+pending:m[day,name]=m.get((day,name),0)+n
 return m
m=grouped('2026-11-20','2026-11-24')
assert sum(v for (day,name),v in m.items() if day>=epoch('2026-11-22'))==12
assert sum(v for (day,name),v in m.items() if day<epoch('2026-11-22'))==3
assert m[epoch('2026-11-22'),'forum_thread_view']==5
with con:
 con.execute('UPDATE ce_forum_analytics_state SET lock_version=lock_version+1 WHERE id=1')
 con.execute('UPDATE ce_forum_analytics_daily SET total=total+1 WHERE day=? AND event_name=?',(epoch('2026-11-22'),'forum_thread_view'))
 con.execute('UPDATE ce_forum_analytics_events SET aggregated_at=1000 WHERE id=?',(f'{2:032x}',))
assert grouped('2026-11-20','2026-11-24')==m
# Test undo is correct after failed aggregation transaction.
try:
 with con:
  con.execute('UPDATE ce_forum_analytics_daily SET total=total+1 WHERE day=? AND event_name=?',(epoch('2026-11-22'),'forum_thread_view'))
  raise RuntimeError('crash')
except RuntimeError:pass
assert grouped('2026-11-20','2026-11-24')==m
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
print('PASS forum dashboard SQLite: applied + pending, previous range, crash rollback, same-counter transfer')
