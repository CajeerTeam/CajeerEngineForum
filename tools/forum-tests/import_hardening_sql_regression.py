#!/usr/bin/env python3
"""Exercise 1.1.11 database checks on the exact migrated SQLite schema."""
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
migrations=sorted((root/'migrations/sqlite').glob('*.sql'))
for path in migrations: con.executescript(path.read_text())
assert len(migrations)>=79
indexes={x[1] for x in con.execute("PRAGMA index_list('ce_forum_import_map')")}
assert 'ce_forum_import_map_target_lookup' in indexes
job='c'*64
alice='a0000000-0000-4000-8000-000000000001'
bob='a0000000-0000-4000-8000-000000000002'
cat='a0000000-0000-4000-8000-000000000003'
board='a0000000-0000-4000-8000-000000000004'
conv='a0000000-0000-4000-8000-000000000005'
for id,email in ((alice,'alice@source.test'),(bob,'bob@source.test')):
    con.execute("INSERT INTO ce_users(id,email,name,password_hash,status,disabled_at) VALUES(?,?,?,'random','disabled',CURRENT_TIMESTAMP)",(id,email,email))
con.execute("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,?,'hidden')",(cat,'Imported',''))
con.execute("INSERT INTO ce_forum_boards(id,category_id,name,description,state,visibility) VALUES(?,?,?,?,'readonly','hidden')",(board,cat,'Archived',''))
con.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,1,1)',(job,'5.0.0',cat,'test','{"pipeline_version":8}'))
for kind,src,target in (('users',1,alice),('users',2,bob),('forums',1,board)):
    con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,?,?,?,'imported')",(job,kind,src,target))
# The same relational predicates are executed by ForumImportIntegrity in PHP/PDO CI.
def exposed():
    return con.execute("""SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_boards b ON b.id=m.target_id
      WHERE m.job_id=? AND m.kind='forums' AND m.outcome='imported'
      AND (b.category_id<>? OR b.visibility<>'hidden' OR b.state<>'readonly')""",(job,cat)).fetchone()[0]
def enabled():
    return con.execute("""SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_users u ON u.id=m.target_id
      WHERE m.job_id=? AND m.kind='users' AND m.outcome='imported' AND (u.status<>'disabled' OR u.disabled_at IS NULL)""",(job,)).fetchone()[0]
def unsafe():
    return con.execute("""SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_conversations c ON c.id=m.target_id
      WHERE m.job_id=? AND m.kind='inv_conversations' AND m.outcome='imported' AND (
        c.owner_id IS NULL OR (SELECT COUNT(*) FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.active=1)<1 OR
        NOT EXISTS(SELECT 1 FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.user_id=c.owner_id AND p.active=1) OR
        EXISTS(SELECT 1 FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND (p.active<>1 OR NOT EXISTS
          (SELECT 1 FROM ce_forum_import_map u WHERE u.job_id=m.job_id AND u.kind='users' AND u.outcome='imported' AND u.target_id=p.user_id))))""",(job,)).fetchone()[0]
assert exposed()==0 and enabled()==0 and unsafe()==0
con.execute("UPDATE ce_forum_boards SET visibility='public' WHERE id=?",(board,)); assert exposed()==1
con.execute("UPDATE ce_forum_boards SET visibility='hidden' WHERE id=?",(board,)); assert exposed()==0
con.execute("UPDATE ce_users SET status='active' WHERE id=?",(alice,)); assert enabled()==1
con.execute("UPDATE ce_users SET status='disabled' WHERE id=?",(alice,)); assert enabled()==0
con.execute("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,'direct',1,1)",(conv,alice,'Secret'))
con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?, 'inv_conversations', 42, ?, 'imported')",(job,conv))
assert unsafe()==1
con.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',(conv,alice))
assert unsafe()==0
con.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',(conv,bob))
assert unsafe()==0
con.execute('UPDATE ce_forum_conversation_participants SET active=0 WHERE conversation_id=? AND user_id=?',(conv,bob))
assert unsafe()==1
con.execute('UPDATE ce_forum_conversation_participants SET active=1 WHERE conversation_id=? AND user_id=?',(conv,bob))
assert unsafe()==0
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS {len(migrations)} migrations, indexed import mappings, quarantine and private recipient corruption cases')
