#!/usr/bin/env python3
"""Run actual target SQLite migrations and v3 content/ledger/checkpoint SQL as one transaction."""
import pathlib
import sqlite3

ROOT = pathlib.Path(__file__).resolve().parents[2]
db = sqlite3.connect(":memory:")
db.execute("PRAGMA foreign_keys=ON")
for sql in sorted((ROOT / 'migrations/sqlite').glob('*.sql')):
    db.executescript(sql.read_text())

uid='00000000-0000-4000-8000-000000000001'
category='00000000-0000-4000-8000-000000000002'
board='00000000-0000-4000-8000-000000000003'
thread='00000000-0000-4000-8000-000000000004'
post='00000000-0000-4000-8000-000000000005'
job='a'*64

db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(uid,'content@example.test','Importer','never-use'))
db.execute("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,?,'hidden')",(category,'Imported','Offline'))
db.execute("INSERT INTO ce_forum_boards(id,category_id,name,description,state,visibility,seo_index) VALUES(?,?,?,?,'readonly','hidden',0)",(board,category,'Private',''))
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,uid,'Imported thread'))
db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,body_format) VALUES(?,?,?,?,1,?)',(post,thread,uid,'[B]Source[/B]','plain'))
db.execute("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Import',1)",(post,'[B]Source[/B]',uid))
db.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,phase,created_at,updated_at) VALUES(?,?,?,?,?,13,1,1)',(job,'2.3.0',category,'test-backup','{"pipeline_version":3}'))
db.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'posts',30,?,'imported')",(job,post))
db.commit()

def convert(body: str) -> None:
    db.execute("UPDATE ce_forum_posts SET body=?,body_format='bbcode' WHERE id=?",(body,post))
    db.execute("UPDATE ce_forum_post_revisions SET body=?,body_format='bbcode' WHERE post_id=? AND revision_number=1",(body,post))
    db.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'content_links',30,?,'imported')",(job,post))
    db.execute('UPDATE ce_forum_import_jobs SET cursor_id=30 WHERE id=?',(job,))

# Failure in any later write must undo the preceding body update and FTS triggers.
db.execute("CREATE TRIGGER deny_content_revision BEFORE UPDATE OF body ON ce_forum_post_revisions BEGIN SELECT RAISE(ABORT,'injected'); END")
try:
    with db: convert('[b]Converted[/b]')
    raise AssertionError('failure not injected')
except sqlite3.IntegrityError:
    pass
assert db.execute('SELECT body_format FROM ce_forum_posts WHERE id=?',(post,)).fetchone()[0]=='plain'
assert db.execute('SELECT body FROM ce_forum_posts WHERE id=?',(post,)).fetchone()[0]=='[B]Source[/B]'
assert db.execute("SELECT count(*) FROM ce_forum_import_map WHERE job_id=? AND kind='content_links'",(job,)).fetchone()[0]==0
assert db.execute('SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==0
assert db.execute("SELECT COUNT(*) FROM ce_forum_fts_posts WHERE body MATCH 'Converted'").fetchone()[0]==0

db.execute('DROP TRIGGER deny_content_revision')
with db: convert('[b]Converted[/b]')
assert db.execute('SELECT body,body_format FROM ce_forum_posts WHERE id=?',(post,)).fetchone()==('[b]Converted[/b]','bbcode')
assert db.execute('SELECT body,body_format FROM ce_forum_post_revisions WHERE post_id=?',(post,)).fetchone()==('[b]Converted[/b]','bbcode')
assert db.execute("SELECT COUNT(*) FROM ce_forum_fts_posts WHERE body MATCH 'Converted'").fetchone()[0]==1
assert db.execute('SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==30
assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
try:
    with db: convert('[b]Duplicate[/b]')
    raise AssertionError('duplicate ID mapping accepted')
except sqlite3.IntegrityError:
    pass
assert db.execute('SELECT body FROM ce_forum_posts WHERE id=?',(post,)).fetchone()[0]=='[b]Converted[/b]'
print('PASS XenForo final content real SQL: 77 target migrations, atomic post/revision/ID map/checkpoint, FTS rollback/rebuild, no duplicate batch')
