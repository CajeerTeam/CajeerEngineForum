#!/usr/bin/env python3
"""Real SQLite source fixture and production target migrations; PHP/PDO execution has its own integration test."""
from pathlib import Path
import sqlite3, base64, hashlib
ROOT=Path(__file__).resolve().parents[2]
source=sqlite3.connect(':memory:')
source.executescript((ROOT/'tools/forum-tests/invision_fixture.sql').read_text())
source.executescript('''
ALTER TABLE ips_core_attachments ADD COLUMN attach_member_id INTEGER;
ALTER TABLE ips_core_attachments ADD COLUMN attach_filesize INTEGER;
CREATE TABLE ips_core_attachments_map(attachment_id INTEGER,location TEXT,id1 INTEGER);
INSERT INTO ips_core_attachments VALUES(7,'original.txt','file.txt',1,7);
INSERT INTO ips_core_attachments_map VALUES(7,'forums_Post',30);
ALTER TABLE ips_forums_topics ADD COLUMN poll_state INTEGER DEFAULT 0;
CREATE TABLE ips_core_polls(pid INTEGER,poll_question TEXT,choices TEXT);
INSERT INTO ips_core_polls VALUES(42,'Pick one','["Yes","No"]');
UPDATE ips_forums_topics SET poll_state=42 WHERE tid=20;
''')
rows=source.execute("""SELECT a.attach_id,(SELECT MIN(id1) FROM ips_core_attachments_map x WHERE x.attachment_id=a.attach_id AND x.location='forums_Post') AS post_id,(SELECT count(*) FROM ips_core_attachments_map x WHERE x.attachment_id=a.attach_id AND x.location='forums_Post') AS map_count FROM ips_core_attachments a WHERE a.attach_id>? AND a.attach_id<=? ORDER BY a.attach_id LIMIT 100""",(0,7)).fetchall()
assert rows==[(7,30,1)]
assert source.execute('SELECT p.pid,t.tid,p.choices FROM ips_core_polls p LEFT JOIN ips_forums_topics t ON t.poll_state=p.pid WHERE p.pid>? AND p.pid<=?',(0,42)).fetchall()==[(42,20,'["Yes","No"]')]
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
versions=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
for file in versions: con.executescript(file.read_text())
u='00000000-0000-4000-8000-000000000001'; cat='00000000-0000-4000-8000-000000000002';board='00000000-0000-4000-8000-000000000003';thread='00000000-0000-4000-8000-000000000004';post='00000000-0000-4000-8000-000000000005';file='00000000-0000-4000-8000-000000000006';job='b'*64
con.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,'invision@example.invalid','Imported','unknown'))
con.execute("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,?,'hidden')",(cat,'Invision','private'))
con.execute("INSERT INTO ce_forum_boards(id,category_id,name,description,state,visibility,seo_index) VALUES(?,?,?,?,'readonly','hidden',0)",(board,cat,'Board',''))
con.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,u,'Thread'))
con.execute("INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,body_format) VALUES(?,?,?,?,1,'plain')",(post,thread,u,'unformatted'))
con.execute("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Import',1)",(post,'unformatted',u))
con.execute("INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,phase,created_at,updated_at) VALUES(?,?,?,?,?,6,1,1)",(job,'4.7.24',cat,'test','{"pipeline_version":7}'))
con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'posts',30,?,'imported')",(job,post))
con.commit()
payload=b'hello!\n';sha=hashlib.sha256(payload).hexdigest()
def add():
    con.execute('INSERT INTO ce_forum_attachments(id,owner_id,post_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',(file,u,post,'file.txt','text/plain',len(payload),sha,base64.b64encode(payload).decode(),1))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'attachments',7,?,'imported')",(job,file))
    con.execute('UPDATE ce_forum_import_jobs SET cursor_id=7 WHERE id=?',(job,))
con.execute("CREATE TRIGGER block_attachment BEFORE INSERT ON ce_forum_import_map WHEN NEW.kind='attachments' BEGIN SELECT RAISE(ABORT,'injected failure'); END")
try:
    with con: add()
    raise AssertionError('expected rollback')
except sqlite3.IntegrityError:pass
assert con.execute('SELECT COUNT(*) FROM ce_forum_attachments').fetchone()[0]==0
assert con.execute('SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==0
con.execute('DROP TRIGGER block_attachment')
with con:add()
with con:
    con.execute("INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility,closes_at,closed) VALUES(?,?,1,0,'always',NULL,1)",(thread,'Pick one'))
    con.executemany('INSERT INTO ce_forum_poll_options(thread_id,option_no,label) VALUES(?,?,?)',[(thread,1,'Yes'),(thread,2,'No')])
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,'polls',42,?,'imported','imported_closed_no_voter_history')",(job,thread))
    con.execute("UPDATE ce_forum_posts SET body=?,body_format='bbcode' WHERE id=?",('[b]Hello[/b]',post))
    con.execute("UPDATE ce_forum_post_revisions SET body=?,body_format='bbcode' WHERE post_id=? AND revision_number=1",('[b]Hello[/b]',post))
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'invision_content',30,?,'imported')",(job,post))
assert con.execute('SELECT COUNT(*) FROM ce_forum_poll_options').fetchone()==(2,)
assert con.execute('SELECT body_format FROM ce_forum_posts WHERE id=?',(post,)).fetchone()==('bbcode',)
assert con.execute("SELECT COUNT(*) FROM ce_forum_fts_posts WHERE body MATCH 'Hello'").fetchone()==(1,)
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS {len(versions)} real SQLite migrations, Invision attachment/poll source joins, atomic rollback, poll options, rich revision and FTS')
