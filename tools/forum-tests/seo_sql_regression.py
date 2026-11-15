#!/usr/bin/env python3
"""Upgrade existing SQLite data and exercise the production sitemap SQL."""
import re, sqlite3, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
for f in files[:-1]:db.executescript(f.read_text())
uid=lambda:str(uuid.uuid4())
board,cat=db.execute('SELECT id,category_id FROM ce_forum_boards LIMIT 1').fetchone()
other,child=uid(),uid()
db.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',(other,cat,'Excluded',''))
db.execute('INSERT INTO ce_forum_boards(id,category_id,name,description,parent_id) VALUES(?,?,?,?,?)',(child,cat,'Child','',other))
def thread(b,body=True,deleted=False):
    t=uid();db.execute('INSERT INTO ce_forum_threads(id,board_id,title,deleted_at) VALUES(?,?,?,?)',(t,b,'Topic '+t,1 if deleted else None))
    if body:db.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',(uid(),t,'Published',1,1))
    return t
old=thread(board);hidden=thread(other);descendant=thread(child);removed=thread(board,deleted=True);empty=thread(board,body=False)
db.commit();assert files[-1].name=='0041_forum_seo_policy.sql';db.executescript(files[-1].read_text())
assert db.execute('SELECT DISTINCT seo_index FROM ce_forum_boards').fetchall()==[(1,)]
db.execute('UPDATE ce_forum_boards SET seo_index=0 WHERE id=?',(other,))
try:db.execute('UPDATE ce_forum_boards SET seo_index=2 WHERE id=?',(board,));raise AssertionError('invalid flag accepted')
except sqlite3.IntegrityError:pass
ids=[old]+[thread(board) for _ in range(1100)]
# Read the actual production SELECT fragments, substituting the resolved guest board predicate and window.
src=(ROOT/'core/Forum/Repository/ForumSitemapRepository.php').read_text()
queries=re.findall(r"'(SELECT [^']+ WHERE )'\.\$this->boardFilter\('t.board_id',\$boards\)\.'([^']+)'",src)
assert len(queries)==3
allowed='t.board_id IN (?)'
def sql(i):return queries[i][0]+allowed+queries[i][1]
assert db.execute(sql(1),(board,)).fetchone()[0]==len(ids)
# Query includes LIMIT with a literal appended by the production repository.
a=[x[0] for x in db.execute(sql(2)+'1000 OFFSET 0',(board,))];b=[x[0] for x in db.execute(sql(2)+'1000 OFFSET 1000',(board,))]
assert a+b==sorted(ids) and not set(a)&set(b)
assert all(x[0] in ids for x in db.execute(sql(0),(board,)))
db.execute('UPDATE ce_forum_posts SET deleted_at=1 WHERE thread_id=?',(old,));assert db.execute(sql(1),(board,)).fetchone()[0]==len(ids)-1
assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS SEO policy upgrade, constraint, sitemap live SQL, guest board exclusion, pagination and soft deletion')
