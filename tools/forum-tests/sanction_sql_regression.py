#!/usr/bin/env python3
"""Real SQLite migration and repository statements; PHP policy checks without vendor."""
import sqlite3, tempfile, uuid, time, subprocess, json, re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
uid=lambda:str(uuid.uuid4())
repo=(ROOT/'core/Forum/Repository/SanctionRepository.php').read_text()
def sql(prefix):
    # The statements are PHP single quoted strings, with escaped quotes for status.
    for match in re.finditer(r"'((?:\\.|[^'\\])*)'",repo):
        s=match.group(1).replace("\\'","'")
        if s.startswith(prefix):return s
    raise AssertionError(prefix)
with tempfile.TemporaryDirectory() as tmp:
    db=sqlite3.connect(Path(tmp)/'db.sqlite');db.execute('PRAGMA foreign_keys=ON')
    migrations=sorted((ROOT/'migrations/sqlite').glob('*.sql'));migrations=[f for f in migrations if f.name[:4]<='0035'];assert migrations[-1].name=='0035_forum_sanctions.sql'
    for f in migrations[:-1]:db.executescript(f.read_text())
    user,admin,other=uid(),uid(),uid();now=int(time.time());board='22222222-2222-4222-8222-222222222222'
    for u in (user,admin,other):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','test'))
    token=uid();db.execute('INSERT INTO ce_api_tokens(id,user_id,name,token_hash) VALUES(?,?,?,?)',(token,user,'admin-session','hash'))
    db.commit();db.executescript(migrations[-1].read_text())
    insert=sql('INSERT INTO ce_forum_sanctions(');active=sql('SELECT * FROM ce_forum_sanctions WHERE user_id=');revoke=sql('UPDATE ce_forum_sanctions SET revoked_at=');appeal=sql('INSERT INTO ce_forum_appeals(');decide=sql('UPDATE ce_forum_appeals SET status=');derived=next(x.group(1) for x in re.finditer(r"'((?:\\.|[^'\\])*)'",repo) if x.group(1).startswith('UPDATE ce_forum_sanctions SET revoked_at=') and 'source_warning_id' in x.group(1))
    warning,post,expired,scoped,ban=[uid() for _ in range(5)]
    def add(id,kind,points=0,ends=None,scope=None,source=None,starts=now):db.execute(insert,(id,user,admin,scope,kind,'Test reason',points,starts,ends,source))
    add(warning,'warning',5,now+86400);add(post,'posting',ends=now+86400,source=warning);add(expired,'upload',ends=now-1,starts=now-100);add(scoped,'reaction',ends=now+100,scope=board)
    db.row_factory=sqlite3.Row
    rows=[dict(r) for r in db.execute(active,(user,now,now))];assert {r['id'] for r in rows}=={warning,post,scoped};assert not list(db.execute(active,(other,now,now)))
    php='''require $argv[1]; $r=json_decode($argv[2],true); $s=\\CajeerEngine\\Forum\\Service\\SanctionService::class; if(!$s::blocks($r,'posting')||$s::blocks($r,'upload')||$s::blocks($r,'reaction')||!$s::blocks($r,'reaction',$argv[3])||$s::automatic(4,5)!==['posting',86400]||$s::automatic(5,10)!==['suspension',604800]||$s::automatic(10,11)!==null||$s::automatic(0,10,0,0)!==null)exit(1);echo "PASS PHP policy and threshold crossing\\n";'''
    subprocess.run(['php','-r',php,str(ROOT/'core/Forum/Service/SanctionService.php'),json.dumps(rows),board],check=True)
    # A warning's derived sanction is unique; revoke both in one transaction.
    try:add(uid(),'posting',ends=now+100,source=warning)
    except sqlite3.IntegrityError:pass
    else:raise AssertionError('duplicate automatic trigger')
    db.execute(revoke,(now,admin,'Appeal accepted',warning));db.execute(derived,(now,admin,'Appeal accepted',warning));assert not any(r['id']==post for r in db.execute(active,(user,now,now)))
    add(ban,'suspension',ends=now+100);db.execute(sql('UPDATE ce_api_tokens SET revoked_at='),(user,));assert db.execute('SELECT revoked_at FROM ce_api_tokens WHERE id=?',(token,)).fetchone()[0]
    aid=uid();db.execute(appeal,(aid,ban,'Appeal reason',now));db.commit()
    try:db.execute(appeal,(uid(),ban,'Duplicate',now))
    except sqlite3.IntegrityError:pass
    else:raise AssertionError('duplicate appeal')
    db.rollback();assert db.execute(decide,('accepted',admin,'Accepted',now,ban)).rowcount==1;db.execute(revoke,(now,admin,'Accepted',ban));db.rollback()
    assert db.execute('SELECT status FROM ce_forum_appeals WHERE id=?',(aid,)).fetchone()[0]=='pending';assert any(r['id']==ban for r in db.execute(active,(user,now,now)))
    assert db.execute(decide,('accepted',admin,'Accepted',now,ban)).rowcount==1;assert db.execute(decide,('rejected',admin,'Stale',now,ban)).rowcount==0;db.execute(revoke,(now,admin,'Accepted',ban));db.commit()
    assert not any(r['id']==ban for r in db.execute(active,(user,now,now)))
    assert not list(db.execute('PRAGMA foreign_key_check'))
    copy=sqlite3.connect(':memory:');db.backup(copy);assert copy.execute('SELECT COUNT(*) FROM ce_forum_sanctions').fetchone()[0]==5
    print('PASS SQLite upgrade, active scope/expiry, unique automatic trigger, token revocation, appeal rollback and restore')
