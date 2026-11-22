#!/usr/bin/env python3
"""Real SQLite source and target SQL regression for XenForo v4 community phases."""
from pathlib import Path
import sqlite3
import uuid
ROOT=Path(__file__).resolve().parents[2]
uid=lambda: str(uuid.uuid4())
source=sqlite3.connect(':memory:')
source.executescript((ROOT/'tools/forum-tests/multi_forum_fixture.sql').read_text())
source.execute('UPDATE xf_user SET avatar_date=123456 WHERE user_id IN (1,2)')
avatars=source.execute('SELECT user_id AS avatar_id,avatar_date FROM xf_user WHERE avatar_date>0 ORDER BY avatar_id LIMIT 100').fetchall()
follows=source.execute('SELECT DISTINCT user_id AS follow_id FROM xf_user_follow ORDER BY follow_id LIMIT 100').fetchall()
assert avatars==[(1,123456),(2,123456)]
assert follows==[(1,),(2,),(3,)]
assert source.execute('SELECT DISTINCT follow_user_id FROM xf_user_follow WHERE user_id=1 AND follow_user_id>2 ORDER BY follow_user_id LIMIT 1').fetchall()==[(3,)]
assert source.execute('SELECT DISTINCT follow_user_id FROM xf_user_follow WHERE user_id=1 AND follow_user_id>3 ORDER BY follow_user_id LIMIT 1').fetchall()==[]
source.close()

db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
files=list(sorted((ROOT/'migrations/sqlite').glob('*.sql')))
for file in files:db.executescript(file.read_text())
job='9'*64;cat=uid();alice=uid();bob=uid();role=uid()
db.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,'not-a-real-password','disabled')",(alice,'community-alice@example.test','Alice'))
db.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,'not-a-real-password','disabled')",(bob,'community-bob@example.test','Bob'))
db.execute("INSERT INTO ce_roles(id,handle,name,permissions) VALUES(?, 'forum-xf-import-test','Legacy admin', '[]')",(role,))
db.execute('INSERT INTO ce_forum_groups(role_id,description) VALUES(?,?)',(role,''))
db.execute('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',(alice,role))
db.execute('INSERT INTO ce_forum_primary_groups(user_id,role_id) VALUES(?,?)',(alice,role))
db.execute("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,?,'hidden')",(cat,'Import','Staged'))
db.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,phase,created_at,updated_at) VALUES(?,?,?,?,?,13,1,1)',(job,'2.3.0',cat,'test-backup','{"pipeline_version":4}'))
for orig,mapped in [(1,alice),(2,bob)]:
 db.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'users',?,?,'imported')",(job,orig,mapped))
db.commit()

def import_community():
 db.execute("INSERT INTO ce_forum_profile_images(user_id,kind,image_data) VALUES(?,'avatar',?)",(alice,'/9j/4AAQSkZJRgABAQ=='))
 db.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'avatars',1,?,'imported')",(job,alice))
 db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(alice,bob))
 db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(bob,alice))
 for orig,mapped in [(1,alice),(2,bob)]:
  db.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,'follows',?,?,'imported')",(job,orig,mapped))
 db.execute("UPDATE ce_forum_import_jobs SET phase=15,cursor_id=0,subcursor_id=0 WHERE id=?",(job,))

db.execute('CREATE TRIGGER reject_community BEFORE INSERT ON ce_forum_member_relations BEGIN SELECT RAISE(ABORT, "inject rollback"); END')
try:
 with db:import_community()
 raise AssertionError('unhandled SQLite rollback fault')
except sqlite3.IntegrityError:pass
assert db.execute('SELECT COUNT(*) FROM ce_forum_profile_images').fetchone()[0]==0
assert db.execute("SELECT COUNT(*) FROM ce_forum_import_map WHERE kind IN ('avatars','follows')").fetchone()[0]==0
assert db.execute('SELECT phase FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==13
db.execute('DROP TRIGGER reject_community')
with db:import_community()
assert db.execute('SELECT COUNT(*) FROM ce_forum_profile_images').fetchone()[0]==1
assert db.execute("SELECT COUNT(*) FROM ce_forum_member_relations WHERE kind='follow'").fetchone()[0]==2
assert db.execute('SELECT COUNT(*) FROM ce_forum_import_map WHERE job_id=? AND kind IN ("avatars","follows")',(job,)).fetchone()[0]==3
assert db.execute('SELECT phase FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==15
assert db.execute('SELECT permissions FROM ce_roles WHERE id=?',(role,)).fetchone()[0]=='[]'
try:
 with db:import_community()
 raise AssertionError('duplicate community mapping accepted')
except sqlite3.IntegrityError:pass
assert db.execute("SELECT COUNT(*) FROM ce_forum_member_relations WHERE kind='follow'").fetchone()[0]==2
assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS XenForo v4 community SQL: {len(files)} migrations, avatar/follows source cursor, atomic rollback, no duplicate and no privilege grants')
