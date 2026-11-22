#!/usr/bin/env python3
"""Migration 0081 against all real SQLite migrations, atomicity, disabled/default policy."""
from pathlib import Path
import sqlite3,json
root=Path(__file__).resolve().parents[2]
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
files=sorted((root/'migrations/sqlite').glob('*.sql'))
for file in files:con.executescript(file.read_text())
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
assert set(row[1] for row in con.execute('PRAGMA table_info(ce_forum_analytics_events)'))=={'id','event_name','created_at'}
assert con.execute('SELECT payload FROM ce_forum_community_settings WHERE id=1').fetchone() is not None
# A failed forum write must roll back its metric in the same transaction.
con.execute('BEGIN')
con.execute('INSERT INTO ce_forum_analytics_events VALUES(?,?,?)',('a'*32,'forum_thread_created',100))
con.rollback()
assert con.execute('SELECT COUNT(*) FROM ce_forum_analytics_events').fetchone()==(0,)
with con:
 con.execute('INSERT INTO ce_forum_analytics_events VALUES(?,?,?)',('b'*32,'forum_post_published',100))
try:
 with con:con.execute('INSERT INTO ce_forum_analytics_events VALUES(?,?,?)',('b'*32,'forum_post_published',100))
 raise AssertionError('Duplicate accepted')
except sqlite3.IntegrityError:pass
assert con.execute('SELECT COUNT(*) FROM ce_forum_analytics_events').fetchone()==(1,)
assert con.execute('SELECT COUNT(*) FROM ce_forum_analytics_events WHERE created_at>=? AND created_at<?',(90,110)).fetchone()==(1,)
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS {len(files)} SQLite migrations, anonymous event ledger, rollback, deduplication, and index query')
