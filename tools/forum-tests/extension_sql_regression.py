#!/usr/bin/env python3
"""Real migration and SQL CAS/lease regression, using the exact SQL in the PHP outbox."""
import json, re, sqlite3, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
source=(ROOT/'core/Forum/Service/ForumExtensionOutbox.php').read_text()
def sql(prefix):
    # Keep the regression tied to production SQL instead of maintaining copies.
    matches=re.findall(r'''->prepare\((['"])(.*?)\1\)''',source,re.S)
    return next(q for _,q in matches if q.startswith(prefix))
insert=sql('INSERT INTO ce_forum_extension_events')
claim=sql('UPDATE ce_forum_extension_events SET lease_token=')
fresh=sql('SELECT * FROM ce_forum_extension_events WHERE id=')
finish=sql('UPDATE ce_forum_extension_events SET progress=')
retry=sql("UPDATE ce_forum_extension_events SET state='pending'")
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'forum.sqlite';a=sqlite3.connect(path);b=sqlite3.connect(path)
    for c in (a,b):c.execute('PRAGMA foreign_keys=ON');c.execute('PRAGMA busy_timeout=5000')
    files=sorted((ROOT/'migrations/sqlite').glob('*.sql'));assert files[-1].name=='0046_forum_extension_events.sql'
    for f in files[:-1]:a.executescript(f.read_text())
    a.executescript(files[-1].read_text());now=int(time.time());eid='a'*32
    params=(eid,'forum.thread.create',json.dumps({'thread_id':'topic'}),now,now,json.dumps(['first','second']),'{}')
    a.execute(insert,params);assert b.execute('SELECT COUNT(*) FROM ce_forum_extension_events').fetchone()[0]==0;a.rollback()
    assert a.execute('SELECT COUNT(*) FROM ce_forum_extension_events').fetchone()[0]==0
    a.execute(insert,params);a.commit()
    assert a.execute(claim,('first',now+300,eid,now,now)).rowcount==1;a.commit()
    assert b.execute(claim,('second',now+300,eid,now,now)).rowcount==0;b.commit()
    assert b.execute(claim,('second',now+601,eid,now+301,now+301)).rowcount==1;b.commit()
    assert a.execute(finish,('{}','done',0,now,None,eid,'first')).rowcount==0;a.commit()
    assert b.execute(fresh,(eid,'second')).fetchone() is not None
    assert b.execute(finish,(json.dumps({'first':'delivered'}),'pending',1,now,None,eid,'second')).rowcount==1;b.commit()
    assert a.execute(claim,('third',now+300,eid,now,now)).rowcount==1;a.commit()
    row=a.execute(fresh,(eid,'third')).fetchone();cols=[c[0] for c in a.execute(fresh,(eid,'third')).description];assert json.loads(dict(zip(cols,row))['progress'])=={'first':'delivered'}
    assert a.execute(finish,(json.dumps({'first':'delivered','second':'dead'}),'dead',0,now,'Subscriber failed',eid,'third')).rowcount==1;a.commit()
    assert a.execute(retry,(json.dumps({'first':'delivered'}),now,eid)).rowcount==1;a.commit()
    assert b.execute(retry,('{}',now,eid)).rowcount==0;b.commit()
    assert a.execute('SELECT state,progress,attempts FROM ce_forum_extension_events').fetchone()==('pending',json.dumps({'first':'delivered'}),0)
    assert not a.execute('PRAGMA foreign_key_check').fetchall()
print('PASS SQLite 0046 upgrade, rollback isolation, two connections, expired lease recovery, fencing, preserved progress and dead-letter retry')
