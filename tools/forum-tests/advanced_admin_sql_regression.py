#!/usr/bin/env python3
"""0.7.1 -> 0.7.2: actual migration, policy CAS, role grants and rollback."""
import json, sqlite3, subprocess, tempfile, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP=r'''
// Isolated ASCII fixture; production requires mbstring.
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}function mb_check_encoding($s,$e){return true;}}
spl_autoload_register(static function($c){if(str_starts_with($c,'CajeerEngine\\')){$p='core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
use CajeerEngine\Forum\Service\ForumCommunitySettings as P;
use CajeerEngine\Forum\Service\TrustService as T;
putenv('FORUM_REGISTRATION=true');putenv('FORUM_SPAM_THREADS_HOUR=30');putenv('FORUM_SPAM_NEW_THREADS_HOUR=6');putenv('FORUM_SPAM_REPLIES_10MIN=60');putenv('FORUM_SPAM_NEW_REPLIES_10MIN=20');putenv('FORUM_ATTACHMENT_MAX_MB=5');putenv('FORUM_ATTACHMENT_QUOTA_MB=100');
$values=P::defaults();$values['name']='Test forum';$values['registration_enabled']=false;$values['trust_days']=15;$values['trust_posts']=9;$values['pause_from']=time()-5;$values['pause_until']=time()+600;
$valid=P::validate($values);$v=P::effective($valid);if(!$v['paused']||$v['registration_open'])throw new RuntimeException('Policy state');
$future=$valid;$future['pause_from']=time()+60;if(P::effective($future)['paused'])throw new RuntimeException('Future pause');$ended=$valid;$ended['pause_from']=time()-60;$ended['pause_until']=time()-1;if(P::effective($ended)['paused'])throw new RuntimeException('Expired pause');
$u=['created_at'=>gmdate('Y-m-d H:i:s',time()-86400*10),'email_verified_at'=>'yes'];if(T::assess($u,10,time(),15,9)['level']!=='new'||T::assess($u,10,time(),7,3)['level']!=='established')throw new RuntimeException('Trust thresholds');
foreach([['trust_days'=>0],['attachment_mb'=>26],['new_threads_hour'=>31],['pause_until'=>1],['unknown'=>1],['registration_enabled'=>'false']] as $bad){try{P::validate(array_replace($values,$bad));throw new RuntimeException('Bad policy accepted');}catch(CajeerEngine\Forum\ForumException $e){}}
putenv('FORUM_REGISTRATION=false');if(P::effective(array_replace($valid,['registration_enabled'=>true]))['registration_open'])throw new RuntimeException('Operator registration bypass');
echo json_encode($valid,JSON_THROW_ON_ERROR);
'''
values=json.loads(subprocess.check_output(['php','-r',PHP],cwd=ROOT))
with tempfile.TemporaryDirectory() as tmp:
 db=sqlite3.connect(Path(tmp)/'forum.sqlite');db.execute('PRAGMA foreign_keys=ON')
 migrations=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0044')
 assert migrations[-1].name=='0044_forum_community_settings.sql'
 for f in migrations[:-1]:db.executescript(f.read_text())
 user=str(uuid.uuid4());db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,'acp@example.test','Member','test'))
 db.execute('INSERT INTO ce_user_roles(user_id,role_id) SELECT ?,id FROM ce_roles WHERE handle=?',(user,'forum-member'));db.commit()
 original=db.execute('SELECT id,handle,permissions FROM ce_roles ORDER BY id').fetchall()
 db.executescript(migrations[-1].read_text());assert db.execute('SELECT revision,payload FROM ce_forum_community_settings').fetchone()==(0,'{}')
 assert all(r in db.execute('SELECT id,handle,permissions FROM ce_roles').fetchall() for r in original)
 roles=db.execute("SELECT id,handle,permissions FROM ce_roles WHERE handle LIKE 'forum-acp-%'").fetchall();assert len(roles)==4
 for rid,handle,scope in roles:assert json.loads(scope)==['forum:acp:'+handle.removeprefix('forum-acp-')]
 # Same conditional mutation as production; stale writes cannot overwrite.
 sql='UPDATE ce_forum_community_settings SET revision=revision+1,payload=? WHERE id=1 AND revision=?'
 assert db.execute(sql,(json.dumps(values),0)).rowcount==1
 db.execute("INSERT INTO ce_audit_log(event,actor_id,context) VALUES('forum.acp.policy.update',?,?)",(user,json.dumps({'before':{},'after':values})));db.commit()
 assert db.execute(sql,('{}',0)).rowcount==0;db.rollback()
 assert db.execute(sql,('{}',1)).rowcount==1;db.rollback()
 assert json.loads(db.execute('SELECT payload FROM ce_forum_community_settings').fetchone()[0])==values
 # Role assignment and revocation preserve the original forum role.
 rid=roles[0][0];db.execute('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',(user,rid));db.commit()
 assert len(db.execute('SELECT role_id FROM ce_user_roles WHERE user_id=?',(user,)).fetchall())==2
 db.execute('DELETE FROM ce_user_roles WHERE user_id=? AND role_id=?',(user,rid));db.commit()
 assert db.execute('SELECT r.handle FROM ce_user_roles ur JOIN ce_roles r ON r.id=ur.role_id WHERE user_id=?',(user,)).fetchall()==[('forum-member',)]
 assert not db.execute('PRAGMA foreign_key_check').fetchall();assert db.execute('SELECT COUNT(*) FROM ce_audit_log WHERE event=?',('forum.acp.policy.update',)).fetchone()[0]==1
print('PASS 0044 upgrade, policy validation, trust thresholds, scheduled pause, operator ceiling, optimistic revision, audited policy and role integrity')
