#!/usr/bin/env python3
"""Cross-cutting real SQLite checks of 0082 rollup SQL, rollback and retention."""
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
c=sqlite3.connect(':memory:')
c.executescript((root/'migrations/sqlite/0081_forum_analytics_events.sql').read_text())
# Exercise an actual 1.2.7 -> 1.2.8 upgrade, not just an empty install.
c.execute('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)',('0'*32,'forum_thread_view',86400))
c.commit()
c.executescript((root/'migrations/sqlite/0082_forum_analytics_rollup.sql').read_text())
assert c.execute('SELECT aggregated_at FROM ce_forum_analytics_events WHERE id=?',('0'*32,)).fetchone()==(None,)
assert c.execute('SELECT COUNT(*) FROM ce_forum_analytics_daily').fetchone()==(0,)
c.execute('DELETE FROM ce_forum_analytics_events WHERE id=?',('0'*32,))
c.commit()
assert {r[1] for r in c.execute('PRAGMA table_info(ce_forum_analytics_events)')} == {'id','event_name','created_at','aggregated_at'}
assert {r[1] for r in c.execute('PRAGMA table_info(ce_forum_analytics_daily)')} == {'day','event_name','total','updated_at'}
assert c.execute('SELECT COUNT(*) FROM ce_forum_analytics_state WHERE id=1').fetchone()[0]==1
assert {'ce_forum_analytics_pending_idx'} <= {r[1] for r in c.execute("PRAGMA index_list('ce_forum_analytics_events')")}
ids=['a'*32,'b'*32,'c'*32]
for row in zip(ids,['forum_thread_view','forum_thread_view','forum_reaction_added'],[86400,86401,86402]):
 c.execute('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)',row)
assert c.execute('SELECT COUNT(*) FROM ce_forum_analytics_events WHERE aggregated_at IS NULL').fetchone()[0]==3
c.execute('BEGIN') if not c.in_transaction else None
c.execute('UPDATE ce_forum_analytics_state SET lock_version=lock_version+1 WHERE id=1')
c.execute('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?) ON CONFLICT(day,event_name) DO UPDATE SET total=ce_forum_analytics_daily.total+excluded.total,updated_at=excluded.updated_at',(86400,'forum_thread_view',2,100))
c.execute('UPDATE ce_forum_analytics_events SET aggregated_at=? WHERE id IN (?,?) AND aggregated_at IS NULL',(100,*ids[:2]))
c.commit()
assert c.execute('SELECT total FROM ce_forum_analytics_daily WHERE day=86400 AND event_name=?',('forum_thread_view',)).fetchone()==(2,)
# Replaying an increment in a transaction that fails marking cannot stick.
try:
 with c:
  c.execute('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?) ON CONFLICT(day,event_name) DO UPDATE SET total=ce_forum_analytics_daily.total+excluded.total,updated_at=excluded.updated_at',(86400,'forum_reaction_added',1,101))
  raise RuntimeError('crash')
except RuntimeError:pass
assert c.execute('SELECT COUNT(*) FROM ce_forum_analytics_daily WHERE event_name=?',('forum_reaction_added',)).fetchone()==(0,)
with c:
 c.execute('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?) ON CONFLICT(day,event_name) DO UPDATE SET total=ce_forum_analytics_daily.total+excluded.total,updated_at=excluded.updated_at',(86400,'forum_reaction_added',1,101))
 c.execute('UPDATE ce_forum_analytics_events SET aggregated_at=101 WHERE id=? AND aggregated_at IS NULL',(ids[2],))
assert c.execute('SELECT SUM(total) FROM ce_forum_analytics_daily').fetchone()==(3,)
with c:
 groups=c.execute('SELECT event_name, created_at-(created_at % 86400) AS day,COUNT(*) AS total FROM ce_forum_analytics_events WHERE aggregated_at IS NOT NULL AND created_at>=? AND created_at<? GROUP BY event_name,created_at-(created_at % 86400)',(86400,172800)).fetchall()
 c.execute('DELETE FROM ce_forum_analytics_daily WHERE day>=? AND day<?',(86400,172800))
 for name,day,total in groups:c.execute('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES (?,?,?,?)',(day,name,total,102))
assert c.execute('SELECT SUM(total) FROM ce_forum_analytics_daily').fetchone()==(3,)
# Retention and failed-job backlog do not interfere.
c.execute('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)',('d'*32,'forum_thread_view',1))
c.execute('DELETE FROM ce_forum_analytics_events WHERE aggregated_at IS NOT NULL AND created_at<?',(200000,))
assert c.execute('SELECT COUNT(*) FROM ce_forum_analytics_events').fetchone()[0]==1
assert c.execute('SELECT SUM(total) FROM ce_forum_analytics_daily').fetchone()==(3,)
assert c.execute('PRAGMA foreign_key_check').fetchall()==[]
print('PASS SQLite migration 0082, SQL upsert, atomic rollback, daily rebuild, retention, and pending backlog')
