#!/usr/bin/env python3
"""Exercise real v1.1.1 source/target SQL schemas with SQLite transactions."""
import sqlite3
import tempfile
import uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def uid(): return str(uuid.uuid4())
with tempfile.TemporaryDirectory() as root:
    target=sqlite3.connect(Path(root)/'target.sqlite')
    target.execute('PRAGMA foreign_keys=ON')
    files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
    for file in files: target.executescript(file.read_text())
    src=sqlite3.connect(':memory:');src.executescript((ROOT/'tools/forum-tests/multi_forum_fixture.sql').read_text())
    profile_sql='SELECT user_id AS profile_id, user_id, about AS bio, location, website, signature FROM xf_user_profile'
    reaction_sql="SELECT reaction_content_id AS reaction_source_id, content_id AS post_id, reaction_user_id AS user_id, reaction_id AS source_type FROM xf_reaction_content WHERE content_type='post'"
    profiles=src.execute('SELECT * FROM ('+profile_sql+') src WHERE profile_id>? AND profile_id<=? ORDER BY profile_id LIMIT 500',(0,3)).fetchall()
    reactions=src.execute('SELECT * FROM ('+reaction_sql+') src WHERE reaction_source_id>? AND reaction_source_id<=? ORDER BY reaction_source_id LIMIT 500',(0,105)).fetchall()
    assert [x[0] for x in profiles]==[1,2,3]
    assert [x[0] for x in reactions]==[100,101,102,103,104,105]
    assert src.execute('SELECT COUNT(*) FROM ('+reaction_sql+') src').fetchone()[0]==6
    job='b'*64;category=uid();alice=uid();bob=uid();board=uid();thread=uid();post1=uid();post2=uid()
    target.execute("INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)",(alice,'mig_alice@example.test','Alice','hash'))
    target.execute("INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)",(bob,'mig_bob@example.test','Bob','hash'))
    target.execute("INSERT INTO ce_forum_categories(id,name,description,position,visibility) VALUES(?,?,?,0,'hidden')",(category,'import',''))
    target.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',(board,category,'board',''))
    target.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,alice,'topic'))
    target.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(post1,thread,alice,'sample',1,1))
    target.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(post2,thread,bob,'reply',2,2))
    target.execute('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',(job,'2.3.0',category,'test-backup','{}',1,1))
    for kind,old,id in [('users',1,alice),('users',2,bob),('posts',30,post1),('posts',31,post2)]:
        target.execute('INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,?)',(job,kind,old,id,'imported',''))
    target.commit()
    def commit_extension(fail=False):
        target.execute('BEGIN')
        for old,new,bio,location,website,signature in profiles[:2]:
            user={1:alice,2:bob}[old]
            target.execute('INSERT INTO ce_forum_member_profiles(user_id,bio,location,website,signature) VALUES(?,?,?,?,?)',(user,bio,location,website,signature))
            target.execute('INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,?)',(job,'profiles',old,user,'imported',''))
        target.execute('INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,?)',(post1,bob,'like'))
        target.execute('INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,?)',(job,'reactions',100,post1,'imported',''))
        target.execute('UPDATE ce_forum_import_jobs SET phase=12,cursor_id=100 WHERE id=?',(job,))
        if fail:
            try:
                target.execute('INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,?)',(post1,bob,'invalid'))
            except sqlite3.IntegrityError:
                target.rollback()
                return
            raise AssertionError('invalid reaction accepted')
        target.commit()
    commit_extension(True)
    assert target.execute('SELECT COUNT(*) FROM ce_forum_member_profiles').fetchone()[0]==0
    assert target.execute('SELECT COUNT(*) FROM ce_forum_reactions').fetchone()[0]==0
    assert target.execute('SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?',(job,)).fetchone()[0]==0
    commit_extension(False)
    assert target.execute('SELECT COUNT(*) FROM ce_forum_member_profiles').fetchone()[0]==2
    assert target.execute('SELECT COUNT(*) FROM ce_forum_reactions').fetchone()[0]==1
    assert target.execute("SELECT COUNT(*) FROM ce_forum_import_map WHERE job_id=? AND kind IN ('profiles','reactions')",(job,)).fetchone()[0]==3
    assert target.execute('PRAGMA foreign_key_check').fetchall()==[]
    print('PASS 1.1.1 real SQL source keyset, target profiles/reactions and atomic data/map/checkpoint rollback')
