#!/usr/bin/env python3
"""Exercise actual repository SQL, PHP content analysis, and independent SQLite writers."""
import json, sqlite3, subprocess, tempfile, time, uuid, threading, re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
repo=(ROOT/'core/Forum/Repository/AntiSpamRepository.php').read_text()
def sql(prefix):
    for match in re.finditer(r"'((?:\\.|[^'\\])*)'",repo):
        s=match.group(1).replace("\\'","'")
        if s.startswith(prefix): return s
    raise AssertionError(prefix)
uid=lambda:str(uuid.uuid4())
usage=sql('SELECT COALESCE(SUM(amount)');insert=sql('INSERT INTO ce_forum_spam_events');delete_prefix=sql('DELETE FROM ce_forum_spam_events');expiry=sql('SELECT id FROM ce_forum_spam_events WHERE created_at<=')
# PHP executes actual renderer, age/verification and content policy without Composer.
php=r'''require 'core/Forum/ForumException.php';require 'core/Forum/Content/ForumContentRenderer.php';require 'core/Forum/Service/AntiSpamService.php';$s=\CajeerEngine\Forum\Service\AntiSpamService::class;
function ck($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$a=$s::analyze("[offer](https://SPAM.example./x)\n\nhttps://ok.example/x\n\n```php\nhttps://ignored.example/a\n```",'markdown');ck($a['hosts']===['spam.example','ok.example'],'actual markdown links and ignored code');
$b=$s::analyze('[url=https://a.example/path]link[/url] [code]https://ignored.example[/code]','bbcode');ck($b['hosts']===['a.example'],'BBCode links');
ck($s::blocked(['sub.spam.example'],'spam.example')&&!$s::blocked(['notspam.example'],'spam.example'),'DNS boundary');
ck($s::blocked($s::analyze('[x](https://%73pam.example/a)','markdown')['hosts'],'spam.example'),'encoded hostname');
$a=$s::analyze('**Some   text**','markdown');$b=$s::analyze('[b]Some text[/b]','bbcode');ck($a['normalized']===$b['normalized'],'format independent fingerprint');
$id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$a=$s::analyze("`@{{$id}}` @{{$id}} @{{$id}}",'markdown');ck($a['mentions']===1,'distinct valid mentions');
$now=time();ck($s::isNew(['created_at'=>gmdate('Y-m-d H:i:s',$now-86400*8),'email_verified_at'=>null],$now),'unverified account');ck(!$s::isNew(['created_at'=>gmdate('Y-m-d H:i:s',$now-86400*8),'email_verified_at'=>'yes'],$now),'established account');
ck($s::limits(true)['thread'][1]<$s::limits(false)['thread'][1],'stricter new account budget');
'''
subprocess.run(['php','-r',php],cwd=ROOT,check=True)
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'db.sqlite';db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON')
    migrations=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name<='0036_forum_antispam.sql');assert migrations[-1].name=='0036_forum_antispam.sql'
    for f in migrations[:-1]:db.executescript(f.read_text())
    user,other=uid(),uid();now=int(time.time())
    for u in (user,other):db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(u,u+'@example.test','Member','hash'))
    db.commit();db.executescript(migrations[-1].read_text())
    def record(c,u,subject,action,when=now,amount=1,fp=None,reason=None):c.execute(insert,(uid(),u,subject,action,when,amount,fp,reason))
    # Same account, independent connections, serialized by the production user-row lock.
    barrier=threading.Barrier(2);out=[]
    def worker():
        c=sqlite3.connect(path,timeout=10);c.execute('PRAGMA foreign_keys=ON');barrier.wait()
        c.execute('BEGIN');c.execute('UPDATE ce_users SET id=id WHERE id=?',(user,))
        used=c.execute(usage,(user,'thread',now-3600)).fetchone()[0]
        if used<1:record(c,user,user,'thread',fp='a'*64);c.commit();out.append(True)
        else:c.rollback();out.append(False)
        c.close()
    ts=[threading.Thread(target=worker) for _ in range(2)]
    for t in ts:t.start()
    for t in ts:t.join()
    assert sorted(out)==[False,True]
    assert db.execute(usage,(user,'thread',now-3600)).fetchone()==(1,)
    assert db.execute(usage,(other,'thread',now-3600)).fetchone()==(0,)
    duplicate=re.search(r'"(SELECT id FROM ce_forum_spam_events WHERE subject=.*?LIMIT 1)"',repo).group(1)
    assert db.execute(duplicate,(user,'a'*64,now-900)).fetchone() and not db.execute(duplicate,(other,'a'*64,now-900)).fetchone()
    # Failed publication rolls the counter back. Hour-boundary expiry is exact.
    record(db,user,user,'reply');db.rollback();assert db.execute(usage,(user,'reply',now-600)).fetchone()==(0,)
    record(db,user,user,'upload_bytes',now-3600,200);record(db,user,user,'upload_bytes',now-1,300);db.commit()
    assert db.execute(usage,(user,'upload_bytes',now-3600)).fetchone()==(300,)
    # Registration attempt is separately committed, uses a shared lock; no raw IP or user ID.
    subject='b'*64
    for _ in range(5):
        db.execute('UPDATE ce_forum_spam_lock SET id=id WHERE id=1');record(db,None,subject,'register');db.commit()
    assert db.execute(usage,(subject,'register',now-3600)).fetchone()==(5,)
    record(db,user,user,'reply',now,fp='c'*64,reason='links');record(db,user,user,'private');record(db,user,user,'report',now-86400);db.commit()
    assert db.execute('SELECT fingerprint,reason FROM ce_forum_spam_events WHERE action=\'private\'').fetchone()==(None,None)
    # Exercise the actual bounded batch DELETE prefix and bind order.
    for _ in range(1100):record(db,user,user,'report',now-86400)
    db.commit()
    for expected in (1000,101):
        ids=[r[0] for r in db.execute(expiry,(now-86400,)).fetchall()]
        assert len(ids)==expected
        for offset in range(0,len(ids),200):
            chunk=ids[offset:offset+200]
            assert db.execute(delete_prefix+','.join('?' for _ in chunk)+')',(now-86400,*chunk)).rowcount==len(chunk)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_spam_events WHERE created_at<=?',(now-86400,)).fetchone()==(0,)
    db.commit();copy=sqlite3.connect(':memory:');db.backup(copy);assert copy.execute('SELECT COUNT(*) FROM ce_forum_spam_events').fetchone()==db.execute('SELECT COUNT(*) FROM ce_forum_spam_events').fetchone()
    db.execute('DELETE FROM ce_users WHERE id=?',(user,));assert db.execute('SELECT COUNT(*) FROM ce_forum_spam_events WHERE user_id=?',(user,)).fetchone()==(0,);assert not list(db.execute('PRAGMA foreign_key_check'))
    print('PASS SQLite upgrade, concurrent account quota, expiry boundary, rollback, registration attempts, privacy, cleanup, backup and cascade')
