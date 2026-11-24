#!/usr/bin/env python3
"""Real SQLite DDL and guarded proposal lifecycle; no fake in-memory repository."""
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
con=sqlite3.connect(':memory:');con.execute('PRAGMA foreign_keys=ON')
for migration in sorted((root/'migrations/sqlite').glob('*.sql')):
    con.executescript(migration.read_text())
count=0
def check(value,label):
    global count
    assert value,label
    count+=1
u='11111111-2222-4333-8444-555555555555'
con.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,'ai-admin@example.invalid','AI Admin','!'))
con.execute('INSERT INTO ce_forum_ai_admin_proposals (id,user_id,action_name,payload_json,before_json,expected_revision,reason,model,state,created_at,expires_at,decided_at,result_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)',
    ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',u,'policy_name','{"name":"New"}','{"name":"Old"}',1,'Approved preview','gpt-4.1-mini','pending',100,1000))
check(con.execute("SELECT COUNT(*) FROM ce_forum_ai_admin_proposals WHERE state='pending'").fetchone()[0]==1,'pending proposal persisted')
check(con.execute("UPDATE ce_forum_ai_admin_proposals SET state='applied',decided_at=200 WHERE id=? AND user_id=? AND state='pending'",('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',u)).rowcount==1,'one decision')
check(con.execute("UPDATE ce_forum_ai_admin_proposals SET state='applied',decided_at=201 WHERE id=? AND user_id=? AND state='pending'",('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',u)).rowcount==0,'replay prevented')
check(con.execute('SELECT decided_at FROM ce_forum_ai_admin_proposals').fetchone()[0]==200,'first decision stays')
try:
    con.execute('INSERT INTO ce_forum_ai_admin_proposals(id,user_id,action_name,payload_json,before_json,reason,model,state,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
        ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','00000000-0000-4000-8000-000000000000','policy_name','{}','{}','test','model','pending',1,3))
    raise AssertionError('orphan proposals allowed')
except sqlite3.IntegrityError: count+=1
con.execute('DELETE FROM ce_users WHERE id=?',(u,))
check(con.execute('SELECT COUNT(*) FROM ce_forum_ai_admin_proposals').fetchone()[0]==0,'account deletion cascades to pending decisions')
print('Forum admin AI SQL / migration:',count,'PASS')
