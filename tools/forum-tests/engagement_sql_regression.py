#!/usr/bin/env python3
"""1.2.10: execute production WHERE/JOIN/ORDER and migration index DDL in SQLite."""
from pathlib import Path
import sqlite3
from datetime import datetime, timezone, timedelta
root=Path(__file__).resolve().parents[2]
db=sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE ce_users(id TEXT PRIMARY KEY,created_at TEXT NOT NULL);
CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT NOT NULL);
CREATE TABLE ce_user_roles(user_id TEXT,role_id TEXT,PRIMARY KEY(user_id,role_id));
CREATE TABLE ce_forum_posts(id TEXT PRIMARY KEY,author_id TEXT,created_at TEXT NOT NULL,ordinal INTEGER NOT NULL,deleted_at TEXT NULL);
CREATE VIEW ce_forum_live_posts AS SELECT * FROM ce_forum_posts WHERE deleted_at IS NULL;
INSERT INTO ce_roles VALUES('member','forum-member'),('staff','forum-moderator'),('cms','cms-editor');
''')
db.executescript((root/'migrations/sqlite/0083_forum_engagement_indexes.sql').read_text())
assert {name for (_,name,*_) in db.execute("PRAGMA index_list('ce_forum_posts')") } >= {'ce_forum_posts_activity_idx'}
for n,(date,role) in enumerate([('2026-11-10','member'),('2026-10-20','staff'),('2026-11-21','member'),('2026-11-10','cms')]):
 db.execute('INSERT INTO ce_users VALUES(?,?)',(str(n),date+' 10:00:00'))
 db.execute('INSERT INTO ce_user_roles VALUES(?,?)',(str(n),role))
for id,author,date,ordinal in [('p1','0','2026-11-10',1),('p2','0','2026-11-11',2),('p3','1','2026-10-27',2),('p4','2','2026-11-22',1),('p5','3','2026-11-22',1)]:
 db.execute('INSERT INTO ce_forum_posts(id,author_id,created_at,ordinal) VALUES(?,?,?,?)',(id,author,date+' 14:00:00',ordinal))
membership="EXISTS (SELECT 1 FROM ce_user_roles ur JOIN ce_roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.handle IN ('forum-member','forum-moderator'))"
people=db.execute('SELECT u.id,u.created_at FROM ce_users u WHERE u.created_at>=? AND u.created_at<? AND '+membership+' ORDER BY u.created_at,u.id',('2026-08-26','2026-11-24')).fetchall()
assert len(people)==3
db.execute("INSERT INTO ce_forum_posts(id,author_id,created_at,ordinal,deleted_at) VALUES('del','0','2026-11-22 14:00:00',2,'2026-11-22 15:00:00')")
posts=db.execute('SELECT p.author_id,p.created_at,p.ordinal FROM ce_forum_live_posts p JOIN ce_users u ON u.id=p.author_id WHERE p.created_at>=? AND p.created_at<? AND '+membership+' ORDER BY p.created_at,p.id',('2026-08-26','2026-11-24')).fetchall()
assert len(posts)==4
assert posts==sorted(posts,key=lambda r:r[1]);assert all(row[0]!='3' for row in posts)
print('PASS 0083 indexes, approved forum roles, bounded registration/post SQL and stable post order')
