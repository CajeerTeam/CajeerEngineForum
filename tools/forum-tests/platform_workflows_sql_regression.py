#!/usr/bin/env python3
"""SQLite migration and transactional invariants of the workflow ledger."""
import sqlite3, tempfile, concurrent.futures, uuid
from pathlib import Path
root=Path(__file__).resolve().parents[2]
checks=0
def check(ok,label):
    global checks
    assert ok,label
    checks+=1
with tempfile.TemporaryDirectory() as tmp:
    db=str(Path(tmp)/'workflow.db'); con=sqlite3.connect(db); con.execute('PRAGMA foreign_keys=ON')
    migrations=sorted((root/'migrations/sqlite').glob('*.sql'))
    for m in migrations: con.executescript(m.read_text())
    check(migrations[-1].name=='0092_platform_workflows.sql','complete upgrade chain')
    a,o,u=(str(uuid.uuid4()) for _ in range(3)); r,t=(str(uuid.uuid4()) for _ in range(2))
    for i in (a,o,u): con.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(i,i+'@example.invalid','Fixture','!'))
    con.execute('INSERT INTO ce_platform_workflows(id,name,owner_id,trigger_name,action_name,operator_id,condition_value,interval_seconds,enabled,revision,next_due,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',(r,'Routing',a,'support.requested','support.assign',o,0,0,1,1,0,100))
    con.execute("INSERT INTO ce_forum_support_tickets(id,user_id,subject,status,needs_human,assigned_to,revision,created_at,updated_at) VALUES(?,?,?,'open',1,NULL,1,100,100)",(t,u,'Support'))
    insert='INSERT INTO ce_platform_workflow_events(id,rule_id,rule_revision,ticket_id,ticket_revision,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(rule_id,rule_revision,ticket_id,ticket_revision) DO NOTHING'
    con.execute(insert,(str(uuid.uuid4()),r,1,t,1,100));con.execute(insert,(str(uuid.uuid4()),r,1,t,1,100));con.commit()
    check(con.execute('SELECT COUNT(*) FROM ce_platform_workflow_events').fetchone()[0]==1,'deduplication')
    try:
        con.execute('BEGIN');con.execute('UPDATE ce_platform_workflow_lock SET revision=revision+1 WHERE id=1');con.execute('UPDATE ce_forum_support_tickets SET assigned_to=?,revision=revision+1 WHERE id=?',(o,t));con.execute('DELETE FROM ce_platform_workflow_events');raise RuntimeError('injected crash')
    except RuntimeError: con.rollback()
    check(con.execute('SELECT assigned_to,revision FROM ce_forum_support_tickets WHERE id=?',(t,)).fetchone()==(None,1),'action rollback')
    check(con.execute('SELECT COUNT(*) FROM ce_platform_workflow_events').fetchone()[0]==1,'event rollback')
    def worker(_):
        c=sqlite3.connect(db,timeout=15);c.execute('PRAGMA foreign_keys=ON')
        try:
            c.execute('BEGIN');c.execute('UPDATE ce_platform_workflow_lock SET revision=revision+1 WHERE id=1');event=c.execute('SELECT id FROM ce_platform_workflow_events ORDER BY created_at,id LIMIT 1').fetchone()
            if event is None:c.commit();return 0
            n=c.execute("UPDATE ce_forum_support_tickets SET assigned_to=?,revision=revision+1 WHERE id=? AND revision=1 AND status='open' AND needs_human=1 AND assigned_to IS NULL",(o,t)).rowcount
            c.execute('DELETE FROM ce_platform_workflow_events WHERE id=?',event);c.execute('INSERT INTO ce_platform_workflow_runs VALUES(?,?,?,?,?,?,?,?)',(str(uuid.uuid4()),r,1,'support.requested','support.assign','done' if n else 'no_match',n,101));c.commit();return n
        finally:c.close()
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool: results=list(pool.map(worker,range(16)))
    check(sum(results)==1,'16 concurrent SQL claim attempts apply once')
    check(con.execute('SELECT assigned_to,revision FROM ce_forum_support_tickets WHERE id=?',(t,)).fetchone()==(o,2),'ticket revision increment once')
    check(con.execute('SELECT COUNT(*),SUM(affected) FROM ce_platform_workflow_runs').fetchone()==(1,1),'atomic history')
    check(con.execute('UPDATE ce_platform_workflows SET revision=revision+1,enabled=0 WHERE id=? AND revision=1',(r,)).rowcount==1,'optimistic edit')
    check(con.execute('UPDATE ce_platform_workflows SET enabled=1 WHERE id=? AND revision=1',(r,)).rowcount==0,'stale edit rejected')
    con.execute(insert,(str(uuid.uuid4()),r,2,t,2,102));con.execute('DELETE FROM ce_forum_support_tickets WHERE id=?',(t,));check(con.execute('SELECT COUNT(*) FROM ce_platform_workflow_events').fetchone()[0]==0,'ticket deletion clears pending metadata')
    con.close()
print(f'Platform workflow SQL: {checks} PASS; {len(migrations)} migrations; 16 concurrent attempts')
