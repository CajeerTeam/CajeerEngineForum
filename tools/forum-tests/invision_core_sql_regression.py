#!/usr/bin/env python3
"""Actual Invision fixture SQL checks; PHP/PDO end-to-end is a separate required CI job."""
from pathlib import Path
import sqlite3
import unittest

ROOT=Path(__file__).resolve().parents[2]
FIXTURE=(ROOT/'tools/forum-tests/invision_fixture.sql').read_text()

class InvisionCoreSQL(unittest.TestCase):
    def db(self, version='4'):
        db=sqlite3.connect(':memory:')
        db.executescript(FIXTURE)
        if version=='5':
            db.executescript("UPDATE ips_core_applications SET app_version='5.0.20'; ALTER TABLE ips_forums_posts RENAME COLUMN post TO post_content;")
        return db

    def test_source_invariants_both_versions(self):
        for major,content in [('4','post'),('5','post_content')]:
            with self.subTest(major=major):
                db=self.db(major)
                users=db.execute('SELECT member_id,name,email,joined,member_group_id FROM ips_core_members WHERE member_id>? AND member_id<=? ORDER BY member_id LIMIT 100',(0,2)).fetchall()
                self.assertEqual([r[0] for r in users],[1,2])
                topics=db.execute("SELECT tid, forum_id, approved FROM ips_forums_topics ORDER BY tid").fetchall()
                self.assertEqual(topics,[(20,11,1)])
                posts=db.execute(f'SELECT p.pid,p.topic_id,t.forum_id,p.{content},p.queued FROM ips_forums_posts p JOIN ips_forums_topics t ON t.tid=p.topic_id ORDER BY p.pid').fetchall()
                self.assertEqual([r[0] for r in posts],[30,31]); self.assertEqual(posts[0][2],11)
                self.assertFalse(any(r[4] for r in posts))

    def test_deleted_and_hidden_content_cannot_be_approved(self):
        db=self.db()
        db.execute("INSERT INTO ips_forums_topics VALUES (21,11,1,'hidden',17000020,0)")
        db.execute("INSERT INTO ips_forums_posts VALUES(32,21,1,17000020,'hidden post',1)")
        visible_topics=db.execute('SELECT tid FROM ips_forums_topics WHERE approved=1').fetchall()
        visible_posts=db.execute('SELECT p.pid FROM ips_forums_posts p JOIN ips_forums_topics t ON p.topic_id=t.tid WHERE p.queued=0 AND t.approved=1').fetchall()
        self.assertEqual(visible_topics,[(20,)]); self.assertEqual(visible_posts,[(30,),(31,)])

    def test_import_ledger_atomicity(self):
        db=self.db()
        db.executescript("CREATE TABLE job(id TEXT PRIMARY KEY,phase INTEGER,cursor_id INTEGER);CREATE TABLE imported(kind TEXT,source_id INTEGER,target_id TEXT,UNIQUE(kind,source_id));INSERT INTO job VALUES('j',0,0);")
        try:
            with db:
                db.execute("INSERT INTO imported VALUES('groups',3,'target-1')")
                db.execute("UPDATE job SET cursor_id=3 WHERE id='j'")
                db.execute("INSERT INTO imported VALUES('groups',3,'duplicate')")
        except sqlite3.IntegrityError:
            pass
        self.assertEqual(db.execute('SELECT COUNT(*) FROM imported').fetchone()[0],0)
        self.assertEqual(db.execute("SELECT cursor_id FROM job WHERE id='j'").fetchone()[0],0)

if __name__=='__main__':unittest.main(verbosity=2)
