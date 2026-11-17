#!/usr/bin/env python3
"""Upgrade a real 0.5.5 SQLite schema, then exercise production safety queries."""
import re
import sqlite3
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
repo = (ROOT/'core/Forum/Repository/ConversationRepository.php').read_text()
notify = (ROOT/'core/Forum/Repository/NotificationRepository.php').read_text()
activity = (ROOT/'core/Forum/Repository/ActivityRepository.php').read_text()

def sql(source, prefix):
    for match in re.finditer(r'\$this->(?:query|row|rows)\(([\'"])(.*?)\1', source):
        if match.group(2).startswith(prefix):
            return match.group(2)
    raise AssertionError(prefix)

db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
files = [f for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')) if f.name[:4] <= '0038']
assert files[-1].name == '0038_forum_trust_safety.sql'
for f in files[:-1]: db.executescript(f.read_text())
a,b,c = [str(uuid.uuid4()) for _ in range(3)]
for u in (a,b,c):
    db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','test'))
db.execute("INSERT INTO ce_forum_conversation_preferences(user_id,incoming) VALUES(?,'none')",(a,))
db.execute("INSERT INTO ce_forum_conversation_preferences(user_id,incoming) VALUES(?,'members')",(b,))
db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(a,c))
db.execute('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES(?,?)',(c,a))
db.commit();db.executescript(files[-1].read_text())
assert db.execute('SELECT COUNT(*) FROM ce_forum_member_relations WHERE user_id=? AND target_id=?',(a,c)).fetchone()==(0,)
db.execute('DELETE FROM ce_forum_user_blocks WHERE user_id=? AND blocked_id=?',(c,a))
assert db.execute('SELECT incoming,contact_mode FROM ce_forum_conversation_preferences WHERE user_id=?',(a,)).fetchone()==('none','members')
assert db.execute('SELECT incoming,contact_mode FROM ce_forum_conversation_preferences WHERE user_id=?',(b,)).fetchone()==('members','members')
update=sql(repo,'UPDATE ce_forum_conversation_preferences SET incoming=')
db.execute(update,('members','trusted',b))
assert db.execute(sql(repo,'SELECT incoming,contact_mode'),(b,)).fetchone()==('members','trusted')
try:
    db.execute(update,('members','invalid',b)); raise AssertionError('Invalid contact mode accepted')
except sqlite3.IntegrityError: pass
# Verify direction of the follow gate and symmetric removal.
for u,v in ((a,b),(b,a),(a,c)):
    db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(u,v))
follows=sql(repo,'SELECT user_id FROM ce_forum_member_relations')
assert db.execute(follows,(b,a)).fetchone()==(b,)
assert db.execute(follows,(c,a)).fetchone() is None
db.execute(sql(repo,'INSERT INTO ce_forum_user_blocks'),(b,a))
blocked=sql(repo,'SELECT user_id FROM ce_forum_user_blocks WHERE')
assert db.execute(blocked,(a,b,b,a)).fetchone()==(b,)
assert db.execute(blocked,(b,a,a,b)).fetchone()==(b,)
db.execute(sql(repo,'DELETE FROM ce_forum_member_relations WHERE kind='),(a,b,b,a))
assert db.execute(follows,(b,a)).fetchone() is None
assert db.execute(follows,(a,b)).fetchone() is None
assert db.execute(follows,(a,c)).fetchone()==(a,)
# Live posts count excludes both soft-deleted posts and soft-deleted threads.
board=db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
t=str(uuid.uuid4());p1,p2,p3=[str(uuid.uuid4()) for _ in range(3)]
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(t,board,a,'Trust topic'))
for i,p in enumerate((p1,p2,p3),1):
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(p,t,a,'Published',i,i))
count=sql(repo,'SELECT COUNT(*) FROM ce_forum_live_posts WHERE author_id=')
assert db.execute(count,(a,)).fetchone()==(3,)
db.execute('UPDATE ce_forum_posts SET deleted_at=?,deletion_id=? WHERE id=?', (1,str(uuid.uuid4()),p3))
assert db.execute(count,(a,)).fetchone()==(2,)
db.execute('UPDATE ce_forum_threads SET deleted_at=?,deletion_id=? WHERE id=?',(1,str(uuid.uuid4()),t))
assert db.execute(count,(a,)).fetchone()==(0,)
db.execute('UPDATE ce_forum_threads SET deleted_at=NULL,deletion_id=NULL WHERE id=?',(t,))
# Execute the actual notification visibility predicate for blocked vs moderation.
# Current production SQL applies recipient and delivery-state predicates inside
# each UNION branch, before joining the visible post/thread ACL filter.
feed_match=re.search(r'\$feed="(.*?)";',notify,re.S)
where_match=re.search(r"return ' FROM '\.\$feed\.'(.*?)'\.\$this->boardFilter",notify,re.S)
assert feed_match and where_match, 'Notification visibility query not found'
feed=feed_match.group(1).replace('$predicate','recipient_id=? AND state=1')
where=where_match.group(1).replace("\\'", "'")
for kind in ('mention','moderation'):
    db.execute('INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,moderation_action,state) VALUES(?,?,?,?,?,?,1)',(str(uuid.uuid4()),b,a,p1,kind,'lock' if kind=='moderation' else None))
visible='SELECT n.kind FROM '+feed+where+'1=1'
assert db.execute(visible,(b,b)).fetchall()==[('moderation',)]
# Real activity SQL fragment filters both directions before pagination.
fragment=re.search(r'\$sql\.="( AND NOT EXISTS \(SELECT 1 FROM ce_forum_user_blocks.*?)";',activity).group(1)
query='SELECT p.id FROM ce_forum_live_posts p WHERE 1=1'+fragment
assert db.execute(query,(b,b)).fetchall()==[]
assert len(db.execute(query,(c,c)).fetchall())==2
# Removing the block restores historical public visibility, but not follow edges.
db.execute('DELETE FROM ce_forum_user_blocks WHERE user_id=? AND blocked_id=?',(b,a))
assert len(db.execute(visible,(b,b)).fetchall())==2
assert db.execute(follows,(b,a)).fetchone() is None
assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
print('PASS 0.5.5 → 0.5.6 migration, trust counts, directional follow, bilateral block and visibility')
