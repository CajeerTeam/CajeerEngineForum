#!/usr/bin/env python3
"""Exercise the real PHP query builder with SQLite, including upgrade and VACUUM."""
import json, sqlite3, subprocess, tempfile, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda:str(uuid.uuid4())
with tempfile.TemporaryDirectory() as tmp:
    db=sqlite3.connect(Path(tmp)/'forum.sqlite');db.row_factory=sqlite3.Row;db.execute('PRAGMA foreign_keys=ON')
    files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
    fts=next(f for f in files if f.name=='0040_forum_full_text_search.sql')
    for f in files[:files.index(fts)]:db.executescript(f.read_text())
    board=db.execute('SELECT id FROM ce_forum_boards').fetchone()[0]
    cat=db.execute('SELECT id FROM ce_forum_categories').fetchone()[0];private=uid()
    db.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',(private,cat,'Private',''))
    def thread(title,b=board):
        t=uid();db.execute('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',(t,b,title));return t
    def post(t,body,n):
        p=uid();db.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',(p,t,body,n,n));return p
    a=thread('Astronomy guide');b=thread('Other title');c=thread('Astronomy private',private);d=thread('Tags only')
    p=post(b,'Astronomy telescopes <script>literal</script>',1);q=post(b,'Astronomy observations',2);post(c,'Astronomy secrets',1)
    db.execute('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)',(d,'astronomy'))
    db.commit();db.executescript(fts.read_text())
    for f in files[files.index(fts)+1:]:db.executescript(f.read_text())
    def search(term,mode='threads',tag=None,allowed=board,offset=0):
        # Execute exactly the production query builder, not a reimplementation.
        code='require "core/Forum/Repository/ForumSearchQuery.php"; $a=json_decode($argv[1],true); echo json_encode(\\CajeerEngine\\Forum\\Repository\\ForumSearchQuery::build("sqlite",$a[0],$a[1],"t.board_id=".chr(39).$a[2].chr(39),$a[3],$a[4]),JSON_THROW_ON_ERROR);'
        sql,params=json.loads(subprocess.check_output(['php','-r',code,json.dumps([term,offset,allowed,tag,mode])],cwd=ROOT))
        return [dict(r) for r in db.execute(sql,params)]
    assert [r['id'] for r in search('astronomy')]==[a,d,b]
    assert {r['post_id'] for r in search('astronomy','posts')}=={p,q}
    assert search('astronomy','posts',allowed=private)[0]['id']==c
    assert search('astronomy telescopes','posts')[0]['post_id']==p
    assert search('astronomy',tag='astronomy')[0]['id']==d and search('',tag='astronomy')[0]['id']==d
    assert search('" OR *')==[] and search('%%')==[]
    assert '<script>literal</script>' in search('telescopes','posts')[0]['match_body']
    # Edits, insertions, tags, moves, soft deletes, restore and actual purge.
    db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('Новые телескопы',p));assert not search('telescopes','posts')
    assert search('ТЕЛЕСКОПЫ','posts')[0]['post_id']==p
    db.execute('UPDATE ce_forum_posts SET deleted_at=1 WHERE id=?',(p,));assert not search('телескопы','posts')
    db.execute('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?',(p,));assert search('телескопы','posts')
    db.execute('UPDATE ce_forum_threads SET board_id=? WHERE id=?',(private,b));assert not search('телескопы','posts')
    assert search('телескопы','posts',allowed=private)[0]['post_id']==p
    db.execute('UPDATE ce_forum_threads SET board_id=? WHERE id=?',(board,b));db.execute('UPDATE ce_forum_threads SET deleted_at=1 WHERE id=?',(b,));assert not search('телескопы','posts')
    db.execute('UPDATE ce_forum_threads SET deleted_at=NULL WHERE id=?',(b,));assert search('телескопы','posts')
    db.execute('UPDATE ce_forum_thread_tags SET tag=? WHERE thread_id=?',('cosmos',d));assert not search('astronomy',tag='cosmos');assert search('cosmos')[0]['id']==d
    db.execute('UPDATE ce_forum_threads SET title=? WHERE id=?',('Galaxies',a));assert search('galaxies')[0]['id']==a
    for n in range(3,30):post(b,'Unique pagination marker' if n==29 else 'filler',n)
    assert search('pagination','posts')[0]['post_page']==2
    # Rollback index updates along with the content.
    db.commit();db.execute('BEGIN');db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('rollbackmarker',p));assert search('rollbackmarker','posts');db.rollback();assert not search('rollbackmarker','posts')
    db.execute('DELETE FROM ce_forum_threads WHERE id=?',(a,));db.commit();db.execute('VACUUM');assert search('телескопы','posts')[0]['post_id']==p
    db.execute('DELETE FROM ce_forum_threads WHERE id=?',(b,));assert not search('телескопы','posts');assert not db.execute('SELECT 1 FROM ce_forum_fts_keys WHERE source_id=?',(p,)).fetchone()
    assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS indexed search upgrade, ACL, relevance, tag and post modes, lifecycle, pagination, rollback, VACUUM and purge')
