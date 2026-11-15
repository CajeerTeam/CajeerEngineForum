#!/usr/bin/env python3
"""Upgrade 0.5.6, exercise production report queries, atomic decisions and privacy."""
import re
import sqlite3
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
uid = lambda: str(uuid.uuid4())
now = int(time.time())
db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
files = sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4] <= '0039')
assert files[-1].name == '0039_forum_safety_stable.sql'
for f in files[:-1]: db.executescript(f.read_text())
a,b,admin,outsider = [uid() for _ in range(4)]
for u in (a,b,admin,outsider): db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','test'))
board = db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
thread,post,conv,msg,public,private,legacy = [uid() for _ in range(7)]
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',(thread,board,a,'Topic'))
db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(post,thread,a,'Public evidence',1,1))
db.execute('INSERT INTO ce_forum_reports(id,post_id,user_id,reason) VALUES(?,?,?,?)',(public,post,b,'Public report'))
db.execute('INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at,last_sequence) VALUES(?,?,?,?,?,?,?)',(conv,a,'Conversation','direct',now,now,1))
db.execute('INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at) VALUES(?,?,?,?,?,?)',(msg,conv,a,1,'Private evidence',now))
db.execute('INSERT INTO ce_forum_conversation_reports(id,message_id,user_id,reason,body_snapshot,created_at) VALUES(?,?,?,?,?,?)',(private,msg,b,'Private report','Private evidence',now))
db.execute('INSERT INTO ce_forum_conversation_reports(id,message_id,user_id,reason,body_snapshot,created_at,status,resolved_by) VALUES(?,?,?,?,?,?,?,?)',(legacy,msg,outsider,'Legacy report','Legacy evidence',now,'dismissed',admin))
db.commit();db.executescript(files[-1].read_text())
assert db.execute('SELECT status,resolved_at,decision,sanction_id FROM ce_forum_conversation_reports WHERE id=?',(legacy,)).fetchone()==('dismissed',None,None,None)

def query(file,prefix):
    source=(ROOT/'core/Forum/Repository'/file).read_text()
    for m in re.finditer(r'\$this->(?:query|row|rows)\(([\'\"])(.*?)\1',source):
        if m.group(2).startswith(prefix): return m.group(2)
    raise AssertionError(prefix)

pub_own=query('ReportRepository.php','SELECT id,post_id,reason,status,category,created_at,resolved_at,decision_kind FROM ce_forum_reports')+'0'
priv_own=query('ConversationRepository.php','SELECT id,message_id,reason,status,created_at,resolved_at FROM ce_forum_conversation_reports WHERE user_id=')+'0'
existing=query('ConversationRepository.php','SELECT id FROM ce_forum_conversation_reports WHERE user_id=')
assert db.execute(existing,(b,msg)).fetchone()==(private,)
assert db.execute(pub_own,(b,)).fetchone()[0]==public
assert db.execute(pub_own,(outsider,)).fetchall()==[]
assert db.execute(priv_own,(a,)).fetchall()==[]
cur=db.execute(priv_own,(b,));assert cur.fetchone()[0]==private
assert set(x[0] for x in cur.description)=={'id','message_id','reason','status','created_at','resolved_at'}
insert=query('SanctionRepository.php','INSERT INTO ce_forum_sanctions(')
link=query('ReportRepository.php','UPDATE ce_forum_reports SET sanction_id=')
resolve=query('ConversationRepository.php','UPDATE ce_forum_conversation_reports SET status=')
public_sid,private_sid=uid(),uid()
# Actual transaction rollback: a decision link cannot point at a nonexistent sanction.
try:
    with db:
        db.execute(insert,(public_sid,a,admin,board,'posting','Rule violation',0,now,now+3600,None))
        db.execute(link,(uid(),public))
except sqlite3.IntegrityError: pass
else: raise AssertionError('missing foreign key')
assert db.execute('SELECT COUNT(*) FROM ce_forum_sanctions').fetchone()==(0,)
assert db.execute('SELECT status,sanction_id FROM ce_forum_reports WHERE id=?',(public,)).fetchone()==('open',None)
with db:
    db.execute(insert,(public_sid,a,admin,board,'posting','Rule violation',0,now,now+3600,None))
    db.execute(link,(public_sid,public))
    # Same optimistic update as ModerationQueueRepository::update(resolve).
    update="UPDATE ce_forum_reports SET status='resolved',resolved_by=?,decision=?,decision_kind=?,resolved_at=?,revision_number=revision_number+1 WHERE id=? AND revision_number=?"
    assert db.execute(update,(admin,'Rule violation','resolved',now,public,1)).rowcount==1
    assert db.execute(update,(admin,'Stale','dismissed',now,public,1)).rowcount==0
assert db.execute(pub_own,(b,)).fetchone()[3]=='resolved'
with db:
    db.execute(insert,(private_sid,a,admin,None,'conversation','Contact restriction',0,now,now+3600,None))
    assert db.execute(resolve,('resolved',admin,now,'Contact restriction',private_sid,private)).rowcount==1
    assert db.execute(resolve,('dismissed',admin,now,'Stale',None,private)).rowcount==0
assert db.execute(priv_own,(b,)).fetchone()[3]=='resolved'
assert db.execute('SELECT body_snapshot FROM ce_forum_conversation_reports WHERE id=?',(private,)).fetchone()==('Private evidence',)
# Revoking an issued sanction must not erase the case decision.
revoke=query('SanctionRepository.php','UPDATE ce_forum_sanctions SET revoked_at=')
with db: assert db.execute(revoke,(now,admin,'Appeal accepted',private_sid)).rowcount==1
assert db.execute('SELECT status,sanction_id FROM ce_forum_conversation_reports WHERE id=?',(private,)).fetchone()==('resolved',private_sid)
# Integrity queries run against real linked data and identify invalid linkage.
source=(ROOT/'core/Forum/ForumIntegrityCheck.php').read_text()
checks={k:s for k,_,s in re.findall(r"^\s*'([a-z_]+)'=>\s*(['\"])(.*?)\2,\s*$",source,re.M) if k in ('invalid_report_sanction','invalid_private_report_decision','invalid_private_report_sanction')}
assert len(checks)==3
assert all(db.execute(q).fetchone()[0]==0 for q in checks.values())
with db: db.execute('UPDATE ce_forum_conversation_reports SET sanction_id=? WHERE id=?',(public_sid,private))
assert db.execute(checks['invalid_private_report_sanction']).fetchone()[0]==1
with db: db.execute('UPDATE ce_forum_conversation_reports SET sanction_id=? WHERE id=?',(private_sid,private))
with db: db.execute('DELETE FROM ce_forum_sanctions WHERE id=?',(private_sid,))
assert db.execute('SELECT status,sanction_id FROM ce_forum_conversation_reports WHERE id=?',(private,)).fetchone()==('resolved',None)
assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS 0.5.6 -> 0.6.0, legacy decisions, owner isolation, atomic rollback, sanction linkage, stale decisions, revocation and integrity checks')
