#!/usr/bin/env python3
"""Use the real 0042 schema: upgrade, cache generations, rollback, visibility and private counters."""
import sqlite3, tempfile, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda:str(uuid.uuid4())
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'forum.sqlite'
    db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON');db.execute('PRAGMA journal_mode=WAL')
    files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0042')
    for f in files[:-1]:db.executescript(f.read_text())
    board=db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
    thread,post=uid(),uid()
    db.execute('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',(thread,board,'Old topic'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',(post,thread,'Old content',1,1));db.commit()
    assert files[-1].name=='0042_forum_cache_generation.sql';db.executescript(files[-1].read_text())
    def generation(conn=db,scope=None):
        return dict(conn.execute('SELECT name,generation FROM ce_forum_cache_generation'+(' WHERE scope=?' if scope else ''),([scope] if scope else [])))
    g=generation();assert len(g)==27 and len(set(g.values()))==27
    other=sqlite3.connect(path);other.execute('PRAGMA foreign_keys=ON')
    other.execute('BEGIN');assert generation(other)==g
    db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('New content',post));db.commit()
    assert generation(other)==g and other.execute('SELECT body FROM ce_forum_posts WHERE id=?',(post,)).fetchone()[0]=='Old content'
    other.commit();new=generation(other);assert new['ce_forum_posts']!=g['ce_forum_posts']
    assert other.execute('SELECT body FROM ce_forum_posts WHERE id=?',(post,)).fetchone()[0]=='New content'
    db.execute('UPDATE ce_forum_boards SET visibility=? WHERE id=?',('private',board));assert generation()['ce_forum_boards']!=new['ce_forum_boards'];db.rollback();assert generation()==new
    db.execute('UPDATE ce_forum_boards SET visibility=? WHERE id=?',('private',board));db.commit();assert generation()['ce_forum_boards']!=new['ce_forum_boards']
    old=generation();db.execute('UPDATE ce_forum_posts SET deleted_at=1 WHERE id=?',(post,));db.commit();assert generation()['ce_forum_posts']!=old['ce_forum_posts'];assert not db.execute('SELECT id FROM ce_forum_live_posts WHERE id=?',(post,)).fetchall()
    old=generation();db.execute('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?',(post,));db.commit();assert generation()['ce_forum_posts']!=old['ce_forum_posts']
    # Private counter changes are isolated from public cache generations.
    users=[uid(),uid()]
    for i,u in enumerate(users):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,f'user{i}@example.test',f'User{i}','unused'))
    db.commit();public=generation(scope='public');private=generation(scope='counter')
    db.execute('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES(?,?)',users);db.commit()
    assert generation(scope='public')==public and generation(scope='counter')['ce_forum_user_blocks']!=private['ce_forum_user_blocks']
    db.execute('DELETE FROM ce_forum_user_blocks WHERE user_id=? AND blocked_id=?',users);db.commit();assert generation(scope='public')==public
    old=generation();db.execute('DELETE FROM ce_forum_threads WHERE id=?',(thread,));db.commit();assert generation()['ce_forum_threads']!=old['ce_forum_threads']
    assert not db.execute('PRAGMA foreign_key_check').fetchall()
    other.close();db.close()
print('PASS 0042 upgrade, committed invalidation, snapshot fencing, rollback, ACL, soft-delete/restore, private generation isolation, purge')
