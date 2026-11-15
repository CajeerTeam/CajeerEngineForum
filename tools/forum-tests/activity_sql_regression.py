#!/usr/bin/env python3
"""Execute the new migration and repository SQL against real SQLite; no PHP PDO required."""
from pathlib import Path
import re
import sqlite3
import uuid

ROOT=Path(__file__).resolve().parents[2]
uid=lambda: str(uuid.uuid4())
files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0027')
assert files[-1].name=='0027_forum_reputation_activity.sql'
clean=sqlite3.connect(':memory:');clean.execute('PRAGMA foreign_keys=ON')
for path in files:clean.executescript(path.read_text())
assert clean.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
assert clean.execute('PRAGMA foreign_key_check').fetchall()==[]
clean.close()
db=sqlite3.connect(':memory:'); db.execute('PRAGMA foreign_keys=ON');db.row_factory=sqlite3.Row
for path in files[:-1]:db.executescript(path.read_text())
a,b,c=uid(),uid(),uid()
for u in (a,b,c):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@test.example','Member','test'))
public='22222222-2222-4222-8222-222222222222';private=uid();cat='11111111-1111-4111-8111-111111111111'
db.execute("INSERT INTO ce_forum_boards(id,category_id,name,description,visibility) VALUES(?,?,?,'','hidden')",(private,cat,'Private'))
posts=[]
for board in (public,private):
 t,p=uid(),uid();posts.append(p)
 db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(t,board,a,'Topic'))
 db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,1,1)',(p,t,a,'Message'))
 db.execute('INSERT INTO ce_forum_reactions(post_id,user_id) VALUES(?,?)',(p,b))
db.commit();db.executescript(files[-1].read_text())
assert [r[0] for r in db.execute('SELECT reaction_type FROM ce_forum_reactions')]==['like','like']
for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')):
 if f.name[:4]>'0027':db.executescript(f.read_text())
source=(ROOT/'core/Forum/Repository/ActivityRepository.php').read_text()
# Extract the literal SQL fragments used by the PHP repository and substitute its server-resolved filters.
score=re.search(r'private const SCORE="(.*?)";',source).group(1)
profile=re.search(r'private const PUBLIC_PROFILE="(.*?)";',source).group(1)
filter_sql="t.board_id IN ('"+public+"')"
stat=re.search(r"\$r=\$this->row\('(SELECT COUNT\(\*\) AS reactions,COALESCE\(SUM\().*?self::SCORE\.'(.*?AND )'\.\$filter",source).groups()
stat_sql=stat[0]+score+stat[1].replace("\\'","'")+filter_sql
assert tuple(db.execute(stat_sql,(a,)).fetchone())==(1,1)
eng=(ROOT/'core/Forum/Repository/EngagementRepository.php').read_text()
update=re.search(r"\$this->query\('(UPDATE ce_forum_reactions SET reaction_type=.*?)'",eng).group(1)
db.execute(update,('helpful',posts[0],b));db.execute(update,('helpful',posts[0],b))
assert tuple(db.execute(stat_sql,(a,)).fetchone())==(1,2)
db.execute('INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,?)',(posts[0],a,'helpful'))
assert tuple(db.execute(stat_sql,(a,)).fetchone())==(1,2)
leader=re.search(r'\$sql="(SELECT u.id,.*?)"\.self::SCORE\."(.*?)"\.self::PUBLIC_PROFILE\.\' AND \'.*?\.'+"' (GROUP BY.*?)'\\.\\$offset",source).groups()
leader_sql=leader[0]+score+leader[1]+profile+' AND '+filter_sql+' '+leader[2]+'0'
assert db.execute(leader_sql).fetchone()['reputation']==2
feed=re.search(r'\$sql="(SELECT p.id AS post_id,.*?)"\.\$this->boardFilter\(.*?\)\."(.*?)";',source).groups()
feed_sql=feed[0]+filter_sql+feed[1]
follow=re.search(r'\$sql\.="( AND EXISTS \(SELECT 1 FROM ce_forum_member_relations f.*?)";',source).group(1)
ignore=re.search(r'\$sql\.="( AND NOT EXISTS \(SELECT 1 FROM ce_forum_member_relations i.*?)";',source).group(1)
assert [r['post_id'] for r in db.execute(feed_sql,(None,0,0))]==[posts[0]]
insert=re.search(r"\$this->query\('(INSERT INTO ce_forum_member_relations.*?)'\.\$suffix",source).group(1)
db.execute(insert,(b,a,'follow'))
assert len(db.execute(feed_sql+follow+ignore,(b,1,1,b,b)).fetchall())==1
db.execute(insert,(b,a,'ignore'));assert db.execute(feed_sql+follow+ignore,(b,1,1,b,b)).fetchall()==[]
db.execute("DELETE FROM ce_forum_member_relations WHERE kind='ignore'")
db.execute("INSERT INTO ce_forum_member_preferences(user_id,activity_visibility) VALUES(?,'private')",(a,))
assert db.execute(feed_sql,(None,0,0)).fetchall()==[]
assert db.execute(leader_sql).fetchall()==[]
assert len(db.execute(feed_sql,(a,1,1)).fetchall())==1
assert db.execute(feed_sql+follow+ignore,(b,1,1,b,b)).fetchall()==[]
db.execute('DELETE FROM ce_forum_member_preferences WHERE user_id=?',(a,))
db.execute('DELETE FROM ce_forum_reactions WHERE post_id=? AND user_id=?',(posts[0],b));assert tuple(db.execute(stat_sql,(a,)).fetchone())==(0,0)
assert db.execute('SELECT COUNT(*) FROM ce_forum_achievement_rules').fetchone()[0]==5
for sql,params in [('UPDATE ce_forum_reactions SET reaction_type=? WHERE post_id=?',('invalid',posts[0])),(insert,(a,a,'follow')),(insert,(b,a,'invalid')),("UPDATE ce_forum_achievement_rules SET threshold=0 WHERE code='first_post'",())]:
 try:db.execute(sql,params)
 except sqlite3.IntegrityError:pass
 else:raise AssertionError('Invalid mutation accepted')
db.commit();db.execute('BEGIN');db.execute("UPDATE ce_forum_achievement_rules SET threshold=2 WHERE code='first_post'");db.rollback()
assert db.execute("SELECT threshold FROM ce_forum_achievement_rules WHERE code='first_post'").fetchone()[0]==1
db.execute('DELETE FROM ce_users WHERE id=?',(b,));assert db.execute('SELECT COUNT(*) FROM ce_forum_member_relations').fetchone()[0]==0
assert db.execute('PRAGMA foreign_key_check').fetchall()==[];assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('PASS legacy reactions, typed scoring, self exclusion, public board filtering, privacy, follows, ignores, rules and rollback')
