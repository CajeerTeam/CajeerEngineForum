#!/usr/bin/env python3
"""SQLite migration and executable repository SQL regression, no PHP extensions needed."""
from pathlib import Path
import re
import sqlite3
import uuid

ROOT=Path(__file__).resolve().parents[2]
uid=lambda: str(uuid.uuid4())
files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0025')
assert files[-1].name=='0025_forum_watches_delivery.sql'
db=sqlite3.connect(':memory:'); db.execute('PRAGMA foreign_keys=ON')
for file in files[:-1]: db.executescript(file.read_text())
a,b=uid(),uid();board='22222222-2222-4222-8222-222222222222';thread=uid();post=uid()
for user in (a,b):db.execute('INSERT INTO ce_users(id,email,name,password_hash,email_verified_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',(user,user+'@example.test','User','test'))
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,1)',(thread,board,a,'Legacy'))
db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,1,1)',(post,thread,a,'Original'))
db.execute('INSERT INTO ce_forum_watches(thread_id,user_id) VALUES(?,?)',(thread,b))
legacy=uid();db.execute("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind) VALUES(?,?,?,?,'mention')",(legacy,b,a,post));db.commit()
db.executescript(files[-1].read_text())
assert db.execute('SELECT mode FROM ce_forum_watches').fetchone()==('internal',)
assert db.execute('SELECT subscription_kind FROM ce_forum_notifications').fetchone()==(None,)
assert db.execute('SELECT COUNT(*) FROM ce_forum_mail_delivery').fetchone()==(0,)
assert db.execute('SELECT body FROM ce_forum_posts').fetchone()==('Original',)
# Execute the actual set-based notification SQL, with the same bind order as PHP.
notify=(ROOT/'core/Forum/Repository/NotificationRepository.php').read_text()
reply_sql=re.search(r'\$this->query\("(INSERT INTO ce_forum_notifications\(event_id,recipient_id,actor_id,post_id,kind,subscription_kind\).*?)",\[Uuid::v4\(\)',notify).group(1).replace('$uuid','?')
reply=uid();db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,2,2)',(reply,thread,a,'Reply'));db.execute('UPDATE ce_forum_threads SET read_sequence=2 WHERE id=?',(thread,));
db.execute(reply_sql,(uid(),a,reply,thread,thread,thread,a))
assert db.execute("SELECT subscription_kind FROM ce_forum_notifications WHERE post_id=?",(reply,)).fetchone()==('thread',)
db.execute("UPDATE ce_forum_watches SET mode='email' WHERE user_id=?",(b,));db.execute(reply_sql,(uid(),a,reply,thread,thread,thread,a));assert db.execute('SELECT COUNT(*) FROM ce_forum_notifications WHERE post_id=?',(reply,)).fetchone()==(1,)
# Actual delivery INSERT and candidate/claim SQL from the production repository.
repo=(ROOT/'core/Forum/Repository/WatchRepository.php').read_text()
def sql(prefix):
    # All relevant statements are literal strings in the repository.
    for match in re.finditer(r'\$this->(?:query|row|rows)\(([\'\"])(.*?)\1',repo):
        if match.group(2).startswith(prefix):return match.group(2)
    raise AssertionError(prefix)
insert=sql('INSERT INTO ce_forum_mail_delivery(');candidate=sql('SELECT id,user_id,mode FROM ce_forum_mail_delivery d')
lease_sql=sql('UPDATE ce_forum_mail_delivery SET lease_token=');retry_sql=sql('UPDATE ce_forum_mail_delivery SET state=?,due_at=');finish_sql=sql('UPDATE ce_forum_mail_delivery SET state=?,sent_at=')
now=1000000;delivery=uid();lease=uid();db.commit();db.execute('BEGIN');db.execute(insert,(delivery,b,reply,board,'thread','email',now));db.rollback();assert db.execute('SELECT COUNT(*) FROM ce_forum_mail_delivery').fetchone()==(0,)
db.execute(insert,(delivery,b,reply,board,'thread','email',now));assert db.execute(candidate,(now,now,now,now-60)).fetchone()==(delivery,b,'email')
db.execute(lease_sql,(lease,now+300,delivery));assert db.execute(candidate,(now,now,now,now-60)).fetchone() is None
# Failed attempt retains pending row and schedules retry; stale lease cannot acknowledge it.
db.execute(retry_sql,('pending',now+60,delivery,lease));assert db.execute(candidate,(now,now,now,now-60)).fetchone() is None
assert db.execute(candidate,(now+60,now+60,now+60,now)).fetchone()==(delivery,b,'email')
new_lease=uid();db.execute(lease_sql,(new_lease,now+360,delivery));assert db.execute(finish_sql,('sent',now+61,delivery,lease)).rowcount==0
assert db.execute(finish_sql,('sent',now+61,delivery,new_lease)).rowcount==1
assert db.execute('SELECT state,attempts,sent_at FROM ce_forum_mail_delivery').fetchone()==('sent',2,now+61)
# Duplicate mail and inconsistent delivery state cannot be persisted.
for query,args in [(insert,(uid(),b,reply,board,'thread','email',now)),("UPDATE ce_forum_mail_delivery SET state='pending' WHERE id=?",(delivery,))]:
    try: db.execute(query,args)
    except sqlite3.IntegrityError: pass
    else: raise AssertionError('constraint not enforced')
# Pending mail cancellation and foreign-key cleanup.
second=uid();db.execute(insert,(second,b,post,board,'board','daily',now+86400));db.execute("UPDATE ce_forum_mail_delivery SET state='cancelled',lease_token=NULL,lease_until=0 WHERE user_id=? AND state='pending'",(b,));assert db.execute('SELECT state FROM ce_forum_mail_delivery WHERE id=?',(second,)).fetchone()==('cancelled',)
db.execute('DELETE FROM ce_forum_threads WHERE id=?',(thread,));assert db.execute('SELECT COUNT(*) FROM ce_forum_mail_delivery').fetchone()==(0,)
assert db.execute('PRAGMA foreign_key_check').fetchall()==[];assert db.execute('PRAGMA integrity_check').fetchone()==('ok',)
print(f'PASS {len(files)} SQLite migrations; upgrade, legacy watches, real notification and delivery SQL, rollback, leases, retry, fencing, deduplication, cancellation, cascade and integrity')
