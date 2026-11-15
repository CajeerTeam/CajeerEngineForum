#!/usr/bin/env python3
"""Execute the PHP production builder on SQLite, including all advanced ACL/filter combinations."""
import json, sqlite3, subprocess, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
u=lambda:str(uuid.uuid4())
db=sqlite3.connect(':memory:');db.row_factory=sqlite3.Row;db.execute('PRAGMA foreign_keys=ON')
for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')):db.executescript(f.read_text())
board=db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0];cat=db.execute('SELECT id FROM ce_forum_categories LIMIT 1').fetchone()[0];hidden=u();second=u()
for x in [hidden,second]:db.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',(x,cat,x,''))
a,b,viewer=u(),u(),u()
for x in [a,b,viewer]:db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(x,x+'@test.invalid',x,'unused'))
def thread(title,owner=a,section=board,day='2026-01-10'):
    t=u();db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,created_at,updated_at) VALUES(?,?,?,?,?,?)',(t,section,owner,title,day+' 00:00:00',day+' 00:00:00'));return t
def post(t,body,owner=a,n=1,day='2026-01-10'):
    p=u();db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,created_at) VALUES(?,?,?,?,?,?,?)',(p,t,owner,body,n,n,day+' 12:00:00'));return p
x=thread('Database index guide');p=post(x,'database index quick brown fox');reply=post(x,'database slow index brown fox',b,2,'2026-01-20')
y=thread('Database tuning',b,day='2026-01-20');post(y,'database index legacy')
z=thread('Unrelated',a,second,'2026-01-15');post(z,'database index');db.execute('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)',(z,'datastore'))
secret=thread('Database secret',a,hidden);post(secret,'database index secret')
q=thread('Database questions',a);qp=post(q,'database index');db.execute('INSERT INTO ce_forum_questions(thread_id) VALUES(?)',(q,))
solved=thread('Database solved',b);post(solved,'database index');answer=post(solved,'accepted reply',b,2);db.execute('INSERT INTO ce_forum_questions(thread_id,accepted_post_id) VALUES(?,?)',(solved,answer))
code='require "core/Forum/Repository/ForumSearchQuery.php"; $a=json_decode($argv[1],true); echo json_encode(\\CajeerEngine\\Forum\\Repository\\ForumSearchQuery::build("sqlite",$a[0],$a[1],$a[2],$a[3],$a[4],$a[5]),JSON_THROW_ON_ERROR);'
def search(term,mode='threads',tag=None,offset=0,**opts):
    sql,args=json.loads(subprocess.check_output(['php','-r',code,json.dumps([term,offset,"t.board_id IN ('%s','%s')"%(board,second),tag,mode,opts])],cwd=ROOT));return [dict(r) for r in db.execute(sql,args)]
def ids(rows,key='id'):return [r[key] for r in rows]
assert p in ids(search('"database index"','posts'),'post_id') and reply not in ids(search('"database index"','posts'),'post_id')
assert ids(search('"quick brown" -legacy','posts'),'post_id')==[p]
assert ids(search('database -slow -legacy','posts'),'post_id').count(reply)==0
assert not search('-database') and not search('"broken')
assert ids(search('database',board_id=second))==[z] and not search('database',board_id=hidden)
assert set(ids(search('database',author_id=a)))=={x,z,q}
assert ids(search('database','posts',author_id=b,**{'from':'2026-01-20 00:00:00'}),'post_id')==[reply]
assert ids(search('database',**{'from':'2026-01-20 00:00:00','to':'2026-01-21 00:00:00'}))==[y]
assert ids(search('database',sort='newest'))[0]==y
assert ids(search('database',sort='oldest'))[-1]==y
assert ids(search('database',sort='replies'))[0] in [x,solved]
assert ids(search('',question='solved'))==[solved]
assert ids(search('',question='unsolved'))==[q] and ids(search('',question='unanswered'))==[q]
assert set(ids(search('data',discover='suggest')))=={x,y,z,q,solved}
assert any(r['matched_tag']=='datastore' for r in search('data',discover='suggest'))
assert secret not in ids(search('database secret',discover='similar'))
assert search('database')[0]['total_results']==5
# Profile/activity filtering and bilateral block/ignore before pagination/count.
db.execute("INSERT INTO ce_forum_member_preferences(user_id,profile_visibility,activity_visibility) VALUES(?,'public','private')",(b,))
assert not search('database',author_id=b) and y not in ids(search('database',discover='similar'))
assert y in ids(search('database',author_id=b,viewer=b))
db.execute('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES(?,?)',(b,viewer))
assert y not in ids(search('database',viewer=viewer)) and reply not in ids(search('database','posts',viewer=viewer),'post_id')
assert search('database',viewer=viewer)[0]['total_results']==3
db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'ignore')",(viewer,a))
assert not search('database',viewer=viewer)
db.execute('UPDATE ce_forum_posts SET deleted_at=1 WHERE id=?',(answer,));assert not search('',question='solved');assert set(ids(search('',question='unanswered')))=={q,solved}
# Pagination and stable count, including hidden boards.
for i in range(30):post(thread('Pagination marker '+str(i)),'Published')
assert len(search('pagination'))==25 and len(search('pagination',offset=25))==5
assert search('pagination',offset=25)[0]['total_results']==30
assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS advanced search: phrases, exclusions, filters, sorting, questions, suggestions, visibility, blocks, count and pagination')
