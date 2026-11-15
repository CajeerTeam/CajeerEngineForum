#!/usr/bin/env python3
"""Execute the actual ACP PHP query builder against the fully migrated SQLite schema."""
import json, sqlite3, subprocess, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP=r'''
// The isolated ASCII fixture can run without mbstring; production requires mbstring.
if (!function_exists('mb_strtolower')) {function mb_strtolower($s){return strtolower($s);}}
require 'core/Forum/ForumException.php'; require 'core/Forum/Validation/ForumValidator.php'; require 'core/Forum/Service/ForumAdminService.php';
[$view,$q,$search,$status,$offset]=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
try { echo json_encode(\CajeerEngine\Forum\Service\ForumAdminService::pageQuery($view,$q,$search,$status,$offset),JSON_THROW_ON_ERROR); }
catch(\CajeerEngine\Forum\ForumException $e){echo json_encode(['error'=>$e->getMessage()]);}
'''
def query(view,q=None,search='',status='all',offset=0):
    return json.loads(subprocess.check_output(['php','-r',PHP,json.dumps([view,q or {},search,status,offset])],cwd=ROOT))
def uid():return str(uuid.uuid4())
db=sqlite3.connect(':memory:');db.row_factory=sqlite3.Row;db.execute('PRAGMA foreign_keys=ON')
for migration in sorted((ROOT/'migrations/sqlite').glob('*.sql')):db.executescript(migration.read_text())
board=db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
for i in range(60):
    db.execute('INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,?,?)',(uid(),f'user{i}@example.test',f'User {i}','test-only','disabled' if i%3==0 else 'active'))
user=uid();db.execute('INSERT INTO ce_users(id,email,name,password_hash,status) VALUES(?,?,?,?,?)',(user,'literal@example.test','Literal %_!','secret-hash','active'))
threads=[]
for i in range(60):
    t=uid();p=uid();threads.append((t,p));db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(t,board,user,f'Thread {i}'));db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,1,1)',(p,t,user,'<script>literal</script>'))
def rows(view,q=None,search='',status='all',offset=0):
    sql,args=query(view,q,search,status,offset);return [dict(r) for r in db.execute(sql,args)]
a=rows('users',status='active');b=rows('users',status='active',offset=25);assert len(a)==26 and len(b)==16 and a[25]['id']==b[0]['id']
assert all(r['status']=='active' and 'password_hash' not in r for r in a+b)
assert [r['id'] for r in rows('users',search='%_!')]==[user]
assert rows('users',search="' OR 1=1 --")==[]
assert len(rows('threads',{'board_id':board}))==26
assert len(rows('posts',{'thread_id':threads[0][0]}))==1 and rows('posts',{'thread_id':threads[0][0]})[0]['excerpt']=='<script>literal</script>'
db.execute('UPDATE ce_forum_threads SET deleted_at=1 WHERE id=?',(threads[0][0],));assert not rows('posts',{'thread_id':threads[0][0]});assert not rows('threads',search='Thread 0')
db.execute("INSERT INTO ce_audit_log(event,actor_id,context) VALUES('forum.acp.member.update',?,'{\"private\":true}')",(user,));db.execute("INSERT INTO ce_audit_log(event,context) VALUES('auth.login','{}')");a=rows('audit');assert len(a)==1 and a[0]['event']=='forum.acp.member.update' and 'context' not in a[0]
assert rows('attachments')==[]
assert 'error' in query('invalid') and 'error' in query('users',status='invalid') and 'error' in query('users',offset=-1)
print('PASS ACP query builder: bounded pages, stable ordering, literal filters, live content, safe projections, audit scope, invalid inputs')
