#!/usr/bin/env python3
"""SQLite production-schema regression of v8 community rows and atomic checkpoints."""
from pathlib import Path
import sqlite3
root = Path(__file__).resolve().parents[2]
con = sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
migrations=sorted((root/'migrations/sqlite').glob('*.sql'))
for file in migrations:
    con.executescript(file.read_text())
uid1='00000000-0000-4000-8000-000000000101';uid2='00000000-0000-4000-8000-000000000102'
cat='00000000-0000-4000-8000-000000000103';board='00000000-0000-4000-8000-000000000104'
thread='00000000-0000-4000-8000-000000000105';post='00000000-0000-4000-8000-000000000106'
conv='00000000-0000-4000-8000-000000000107';message='00000000-0000-4000-8000-000000000108';job='f'*64
for u,email in [(uid1,'source-alice@example.test'),(uid2,'source-bob@example.test')]:
    con.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,'random','disabled')",(u,email,email))
    con.execute("INSERT INTO ce_forum_member_preferences(user_id,profile_visibility,activity_visibility) VALUES(?,'private','private')",(u,))
con.execute("INSERT INTO ce_forum_categories(id,name,description,visibility) VALUES(?,?,?,'hidden')",(cat,'Import',''))
con.execute("INSERT INTO ce_forum_boards(id,category_id,name,description,state,visibility,seo_index) VALUES(?,?,?,?,'readonly','hidden',0)",(board,cat,'Board',''))
con.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,uid1,'Imported'))
con.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal) VALUES(?,?,?,?,1)',(post,thread,uid1,'original'))
con.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,1,1)',(job,'4.7.24',cat,'test','{"pipeline_version":8}'))
con.commit()

def save():
    con.execute("INSERT INTO ce_forum_member_profiles(user_id,bio) VALUES(?,?)",(uid1,'Safe profile'))
    con.execute("INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,'like')",(post,uid2))
    con.execute("INSERT INTO ce_forum_watches(thread_id,user_id,mode) VALUES(?,?,'internal')",(thread,uid2))
    con.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(uid2,uid1))
    con.execute("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,'direct',1,1)",(conv,uid1,'Secret'))
    con.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',(conv,uid1))
    con.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',(conv,uid2))
    con.execute("INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,body_format,created_at) VALUES(?,?,?,?,?,'bbcode',1)",(message,conv,uid1,1,'[b]Secret[/b]'))
    con.execute("INSERT INTO ce_forum_conversation_revisions(message_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'bbcode',?,'Invision import',1)",(message,'[b]Secret[/b]',uid1))
    con.execute('UPDATE ce_forum_conversations SET last_sequence=1 WHERE id=?',(conv,))
    for kind,source,target in [('inv_profiles',1,uid1),('inv_reactions',2,post),('inv_follow',11,uid2),('inv_conversations',60,conv),('inv_messages',70,message),('inv_pm_content',70,message)]:
        con.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome) VALUES(?,?,?,?,'imported')",(job,kind,source,target))
    con.execute('UPDATE ce_forum_import_jobs SET cursor_id=70 WHERE id=?',(job,))

con.execute("CREATE TRIGGER test_fail BEFORE INSERT ON ce_forum_import_map WHEN NEW.kind='inv_pm_content' BEGIN SELECT RAISE(ABORT,'deliberate'); END")
try:
    with con:save()
    raise AssertionError('expected rollback')
except sqlite3.IntegrityError:
    pass
assert con.execute('SELECT COUNT(*) FROM ce_forum_conversations').fetchone()==(0,)
assert con.execute('SELECT COUNT(*) FROM ce_forum_reactions').fetchone()==(0,)
assert con.execute('SELECT COUNT(*) FROM ce_forum_member_profiles').fetchone()==(0,)
assert con.execute('SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()==(0,)
con.execute('DROP TRIGGER test_fail')
with con:save()
assert con.execute("SELECT body,body_format FROM ce_forum_conversation_messages").fetchone()==('[b]Secret[/b]','bbcode')
assert con.execute('SELECT count(*) FROM ce_forum_conversation_participants WHERE conversation_id=?',(conv,)).fetchone()==(2,)
assert con.execute("SELECT count(*) FROM ce_forum_member_relations WHERE kind='follow'").fetchone()==(1,)
assert con.execute('SELECT profile_visibility FROM ce_forum_member_preferences WHERE user_id=?',(uid1,)).fetchone()==('private',)
assert con.execute('SELECT visibility FROM ce_forum_categories WHERE id=?',(cat,)).fetchone()==('hidden',)
assert con.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS {len(migrations)} real migrations, v8 profile/reaction/follow/private message schema, atomic rollback, imported privacy')
