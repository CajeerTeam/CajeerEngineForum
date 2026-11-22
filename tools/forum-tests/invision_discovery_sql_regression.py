#!/usr/bin/env python3
"""Actual SQLite fixture and relational integrity smoke test independent of PHP drivers."""
from pathlib import Path
import sqlite3

root=Path(__file__).resolve().parents[2]
sql=(root/'tools/forum-tests/invision_fixture.sql').read_text()
con=sqlite3.connect(':memory:')
con.executescript(sql)
assert con.execute("SELECT app_version FROM ips_core_applications WHERE app_directory='core'").fetchone()[0]=='4.7.24'
assert con.execute('SELECT COUNT(*) FROM ips_forums_posts').fetchone()[0]==2
assert con.execute('SELECT COUNT(*) FROM ips_forums_topics').fetchone()[0]==1
assert con.execute('SELECT COUNT(*) FROM ips_forums_posts p LEFT JOIN ips_forums_topics t ON p.topic_id=t.tid WHERE t.tid IS NULL').fetchone()[0]==0
con.execute('UPDATE ips_forums_posts SET topic_id=999 WHERE pid=31')
assert con.execute('SELECT COUNT(*) FROM ips_forums_posts p LEFT JOIN ips_forums_topics t ON p.topic_id=t.tid WHERE t.tid IS NULL').fetchone()[0]==1
con.rollback()
con.execute("UPDATE ips_core_applications SET app_version='5.0.20' WHERE app_directory='core'")
con.execute('ALTER TABLE ips_forums_posts RENAME COLUMN post TO post_content')
assert 'post_content' in [r[1] for r in con.execute('PRAGMA table_info(ips_forums_posts)')]
print('Invision 4/5 SQL fixture and relational scans: PASS')
