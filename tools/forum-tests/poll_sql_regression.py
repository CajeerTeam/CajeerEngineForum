#!/usr/bin/env python3
"""Run actual 0031 migration and production repository statements on SQLite."""
import re
import sqlite3
import tempfile
import uuid
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(__file__).resolve().parents[2]
uid = lambda: str(uuid.uuid4())
source = (ROOT/'core/Forum/Repository/PollQuestionRepository.php').read_text()

def sql(prefix):
    return next(text for _, text in re.findall(r"(['\"])([^\n]*?)\1", source) if text.startswith(prefix))

def conn(path):
    db=sqlite3.connect(path,timeout=20);db.execute('PRAGMA foreign_keys=ON');return db

def reject(db, query, args):
    try: db.execute(query,args)
    except sqlite3.IntegrityError: return
    raise AssertionError('Expected FK or unique constraint failure')

with tempfile.TemporaryDirectory(prefix='forum-poll-') as tmp:
    path=Path(tmp)/'live.sqlite';db=conn(path)
    migrations=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0031')
    assert migrations[-1].name=='0031_forum_polls_questions.sql'
    for f in migrations[:-1]:db.executescript(f.read_text())
    users=[uid() for _ in range(8)];owner,answerer,voter=users[:3]
    for user in users:db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,user+'@example.test','Member','test'))
    board='22222222-2222-4222-8222-222222222222'
    poll,q,ordinary=[uid() for _ in range(3)];first,answer,second,other=[uid() for _ in range(4)]
    for t in [poll,q,ordinary]:db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,3)',(t,board,owner,'Old topic'))
    for t,p,a,n in [(q,first,owner,1),(q,answer,answerer,2),(q,second,voter,3),(ordinary,other,owner,1)]:db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(p,t,a,'Original',n,n))
    db.commit();db.executescript(migrations[-1].read_text())
    assert db.execute('SELECT body FROM ce_forum_posts WHERE id=?',(first,)).fetchone()==('Original',)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_polls').fetchone()==(0,)
    for later in sorted((ROOT/'migrations/sqlite').glob('*.sql')):
        if later.name[:4]>'0031':db.executescript(later.read_text())
    create=sql('INSERT INTO ce_forum_polls(');option=sql('INSERT INTO ce_forum_poll_options(');vote=sql('INSERT INTO ce_forum_poll_votes(');delete=sql('DELETE FROM ce_forum_poll_votes');tally=sql('SELECT option_no,COUNT(*)');voters=sql('SELECT COUNT(DISTINCT user_id)');accept=sql('UPDATE ce_forum_questions SET accepted_post_id=?');av=sql('INSERT INTO ce_forum_answer_votes(')
    db.execute(create,(poll,'Choose',2,1,'voted',None))
    for n in range(1,4):db.execute(option,(poll,n,'Option '+str(n)))
    db.execute(vote,(poll,voter,1));db.execute(vote,(poll,voter,2))
    reject(db,vote,(poll,voter,1));reject(db,vote,(poll,voter,4));reject(db,vote,(ordinary,voter,1))
    assert db.execute(voters,(poll,)).fetchone()==(1,)
    assert db.execute(tally,(poll,)).fetchall()==[(1,1),(2,1)]
    db.commit()
    db.execute(delete,(poll,voter));db.execute(vote,(poll,voter,3));db.rollback()
    assert db.execute(tally,(poll,)).fetchall()==[(1,1),(2,1)]
    db.execute(delete,(poll,voter));db.execute(vote,(poll,voter,3));db.commit()
    assert db.execute(tally,(poll,)).fetchall()==[(3,1)]
    # Real independent connections serialize writes to the thread. Replace vote set atomically.
    def cast(user):
        c=conn(path)
        try:
            c.execute('BEGIN IMMEDIATE');c.execute('UPDATE ce_forum_threads SET id=id WHERE id=?',(poll,));c.execute(delete,(poll,user));c.execute(vote,(poll,user,1));c.commit()
        finally:c.close()
    with ThreadPoolExecutor(max_workers=8) as ex:list(ex.map(cast,users*2))
    assert db.execute(voters,(poll,)).fetchone()==(len(users),)
    assert db.execute(tally,(poll,)).fetchall()==[(1,len(users))]
    db.execute(sql('INSERT INTO ce_forum_questions('),(q,));db.execute(accept,(answer,q));db.execute(av,(answer,voter));db.execute(av,(second,owner));db.execute(av,(second,answerer));reject(db,av,(second,answerer))
    ranking=sql('SELECT p.id,p.ordinal,p.author_id')+'score DESC,p.ordinal LIMIT 25 OFFSET 0'
    assert [r[0] for r in db.execute(ranking,(owner,q))]==[second,answer]
    assert db.execute('SELECT accepted_post_id FROM ce_forum_questions WHERE thread_id=?',(q,)).fetchone()==(answer,)
    # The accepted event is persisted with the decision, then promoted by the shared worker.
    notify=(ROOT/'core/Forum/Repository/NotificationRepository.php').read_text()
    feed=re.search(r'private const FEED="(.*?)";',notify).group(1)
    enqueue="INSERT INTO ce_forum_answer_notifications(event_id,recipient_id,actor_id,post_id) SELECT ?,u.id,?,? FROM ce_users u WHERE u.id=? AND u.status='active' AND COALESCE((SELECT pref.accepted FROM ce_forum_notification_preferences pref WHERE pref.user_id=u.id),1)=1"
    event=uid();db.execute(enqueue,(event,owner,answer,answerer));reject(db,enqueue,(uid(),owner,answer,answerer))
    assert db.execute('SELECT event_id,recipient_id,kind FROM '+feed+' n WHERE state=0').fetchall()==[(event,answerer,'accepted')]
    db.execute('UPDATE ce_forum_answer_notifications SET state=1 WHERE event_id=? AND recipient_id=? AND state=0',(event,answerer))
    assert db.execute('SELECT COUNT(*) FROM '+feed+' n JOIN ce_forum_posts p ON p.id=n.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE n.recipient_id=? AND n.state=1 AND t.board_id=? AND n.is_read=0',(answerer,board)).fetchone()==(1,)
    assert db.execute('SELECT COUNT(*) FROM '+feed+' n WHERE n.recipient_id=? AND n.state=1',(owner,)).fetchone()==(0,)
    db.execute('UPDATE ce_forum_answer_notifications SET is_read=1 WHERE event_id=? AND recipient_id=? AND state=1',(event,answerer))
    assert db.execute('SELECT is_read FROM '+feed+' n WHERE event_id=?',(event,)).fetchone()==(1,)
    db.commit()
    detach=sql('UPDATE ce_forum_questions SET accepted_post_id=NULL');clear=next(text for _,text in re.findall(r"(['\"])([^\n]*?)\1",source) if text=='DELETE FROM ce_forum_answer_votes WHERE post_id=?')
    db.execute(detach,(q,answer));db.execute(clear,(answer,));db.execute('DELETE FROM ce_forum_answer_notifications WHERE post_id=?',(answer,));db.execute('UPDATE ce_forum_posts SET thread_id=?,ordinal=2,read_sequence=4 WHERE id=?',(ordinary,answer));db.rollback()
    assert db.execute('SELECT accepted_post_id FROM ce_forum_questions WHERE thread_id=?',(q,)).fetchone()==(answer,)
    db.execute(detach,(q,answer));db.execute(clear,(answer,));db.execute('DELETE FROM ce_forum_answer_notifications WHERE post_id=?',(answer,));db.execute('UPDATE ce_forum_posts SET thread_id=?,ordinal=2,read_sequence=4 WHERE id=?',(ordinary,answer));db.execute('UPDATE ce_forum_threads SET read_sequence=4 WHERE id=?',(ordinary,));db.commit()
    assert db.execute('SELECT accepted_post_id FROM ce_forum_questions WHERE thread_id=?',(q,)).fetchone()==(None,)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_answer_votes WHERE post_id=?',(answer,)).fetchone()==(0,)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_answer_notifications').fetchone()==(0,)
    # Audit queries reject impossible votes, cross-thread accepted posts and double types.
    audit=(ROOT/'core/Forum/ForumIntegrityCheck.php').read_text()
    checks={k:s for k,_,s in re.findall(r"^\s*'([a-z_]+)'=>\s*(['\"])(.*?)\2,\s*$",audit,re.M) if k.startswith('invalid_poll') or k in ('invalid_question_type','invalid_accepted_answer','invalid_answer_vote')}
    assert len(checks)==5 and all(db.execute(s).fetchone()==(0,) for s in checks.values())
    db.execute(accept,(answer,q));assert db.execute(checks['invalid_accepted_answer']).fetchone()==(1,);db.rollback()
    db.execute(vote,(poll,owner,2));db.execute(vote,(poll,owner,3));assert db.execute(checks['invalid_poll_votes']).fetchone()==(1,);db.rollback()
    db.execute(accept,(second,q));db.execute('DELETE FROM ce_forum_posts WHERE id=?',(second,));assert db.execute('SELECT accepted_post_id FROM ce_forum_questions WHERE thread_id=?',(q,)).fetchone()==(None,)
    db.execute('DELETE FROM ce_forum_threads WHERE id=?',(poll,));assert db.execute('SELECT COUNT(*) FROM ce_forum_poll_votes').fetchone()==(0,)
    db.commit();assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print('PASS SQLite upgrade, real vote/tally queries, concurrent replacement, ranking, acceptance notification, transfer, integrity and cascade')
