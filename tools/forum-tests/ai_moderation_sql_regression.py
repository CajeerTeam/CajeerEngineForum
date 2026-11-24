#!/usr/bin/env python3
"""Executable SQLite upgrade test for the persisted AI moderation advice and retention."""
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
db=sqlite3.connect(':memory:')
for path in sorted((root/'migrations/sqlite').glob('*.sql')):
    db.executescript(path.read_text())
    if path.name=='0088_forum_ai_moderation.sql': break
else: raise AssertionError('0088 migration not found')
assert db.execute("SELECT name FROM sqlite_master WHERE type='index' AND name='ce_forum_ai_moderation_retention'").fetchone()
uid='11111111-1111-4111-8111-111111111111'
id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
params=('report',id,1,'a'*64,'{"risk":"low"}','gpt-4.1-mini',uid,1000)
db.execute('INSERT INTO ce_forum_ai_moderation_reviews(kind,case_id,revision_number,source_hash,assessment_json,model,reviewed_by,created_at) VALUES(?,?,?,?,?,?,?,?)',params)
assert db.execute('SELECT revision_number FROM ce_forum_ai_moderation_reviews WHERE kind=? AND case_id=?',('report',id)).fetchone()==(1,)
try: db.execute('INSERT INTO ce_forum_ai_moderation_reviews(kind,case_id,revision_number,source_hash,assessment_json,model,reviewed_by,created_at) VALUES(?,?,?,?,?,?,?,?)',params)
except sqlite3.IntegrityError: pass
else: raise AssertionError('Duplicate case review accepted')
db.execute('UPDATE ce_forum_ai_moderation_reviews SET source_hash=?,revision_number=?,created_at=? WHERE kind=? AND case_id=?',('b'*64,2,2000,'report',id))
assert db.execute('SELECT source_hash,revision_number FROM ce_forum_ai_moderation_reviews WHERE kind=? AND case_id=?',('report',id)).fetchone()==('b'*64,2)
db.execute('DELETE FROM ce_forum_ai_moderation_reviews WHERE created_at<?',(2001,))
assert db.execute('SELECT COUNT(*) FROM ce_forum_ai_moderation_reviews').fetchone()==(0,)
assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('Forum AI Moderation: SQLite migrations, case uniqueness, revision invalidation, 30-day retention, integrity PASS')
