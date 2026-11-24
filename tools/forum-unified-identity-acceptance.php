<?php
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository,UserSessionRepository,ApiTokenRepository,AuditLogRepository};
use CajeerEngine\Identity\UnifiedAccess;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Request;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Real PDO required');
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_unified_identity');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA foreign_keys=ON');(new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec(file_get_contents($file));}
else{$pdo=$db->connection();if($pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn()!=='forum_unified_identity')throw new RuntimeException('Unsafe database');(new MigrationRunner($pdo,$root.'/migrations'))->run($driver);}
$roles=new RoleRepository($db);$roles->seedDefaults();$users=new UserRepository($db,$roles);$sessions=new UserSessionRepository($db);$tokens=new ApiTokenRepository($db);$svc=new UnifiedAccess($db,$sessions,new AuditLogRepository($db));
$ids=[];foreach(['admin','member','other'] as $key){$id=\CajeerEngine\Support\Uuid::v4();$pdo->prepare("INSERT INTO ce_users (id,email,name,password_hash,two_factor_recovery_codes) VALUES (?,?,?,?,'[]')")->execute([$id,$id.'@example.invalid',$key,'!']);$ids[$key]=$id;}
$a=$ids['admin'];$u=$ids['member'];$o=$ids['other'];$users->syncRoles($a,['admin']);$users->syncRoles($u,['forum-member','forum-acp-members','viewer']);$users->syncRoles($o,['forum-member']);
$n=0;$check=function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$reject=function(callable $fn,string $label)use($check){try{$fn();}catch(DomainException|InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException('Unsafe operation: '.$label);};
try{
 $old=$svc->inspect($u);$check(in_array('forum:acp:members',$old['effective_scopes'],true),'live delegated scopes');
 $local=$tokens->create($u,'admin-session',['forum:write']);$sso=$tokens->create($u,'admin-session',['forum:write']);$machine=$tokens->create($u,'integration',['forum:write']);$other=$tokens->create($o,'admin-session',['forum:write']);
 foreach([[$local,$u,'local'],[$sso,$u,'skif4er'],[$other,$o,'local']] as [$t,$id,$provider])$sessions->register($t['id'],$id,$provider,Request::create('https://forum.test'),null);
 $reject(fn()=>$svc->assign($a,$u,$old['revision'],['admin']),'wildcard role refused');
 $reject(fn()=>$svc->assign($a,$u,$old['revision'],['forum-acp-settings']),'managed role refused');
 $reject(fn()=>$svc->assign($a,$a,$svc->inspect($a)['revision'],['viewer']),'self grant refused');
 $reject(fn()=>$svc->assign($o,$u,$old['revision'],['viewer']),'unprivileged actor refused');
 $new=$svc->assign($a,$u,$old['revision'],['editor']);
 $check(in_array('content:write',$new['effective_scopes'],true)&&!in_array('system:read',$new['effective_scopes'],true),'actual role change');
 $check(in_array('forum:acp:members',$new['effective_scopes'],true)&&in_array('forum:write',$new['effective_scopes'],true),'forum roles preserved');
 $q=$pdo->prepare('SELECT role_id FROM ce_forum_primary_groups WHERE user_id=?');$q->execute([$u]);$check($q->fetchColumn()===$roles->findIdByHandle('forum-member'),'primary group preserved');
 $check($tokens->find($local['id'])['revoked_at']!==null&&$tokens->find($sso['id'])['revoked_at']!==null,'local and SSO revoked');
 $check($tokens->find($machine['id'])['revoked_at']===null,'machine token untouched');
 $reject(fn()=>$svc->assign($a,$u,$old['revision'],['viewer']),'stale revision refused');
 $reject(fn()=>$svc->revoke($a,$u,$other['id']),'other user session refused');
 $check($svc->revoke($a,$o,$other['id'])===1,'owned session revoked');
 $reject(fn()=>$svc->revoke($a,$o,$other['id']),'repeated session revoke refused');
 $q=$pdo->prepare("SELECT COUNT(*) FROM ce_audit_log WHERE actor_id=? AND event='identity.admin_roles_change'");$q->execute([$a]);$check((int)$q->fetchColumn()===1,'transactional role audit');
 $users->syncRoles($a,['viewer']);$reject(fn()=>$svc->assign($a,$u,$new['revision'],['viewer']),'revoked administrator refused');
 echo "Unified Identity {$driver}: {$n} PASS\n";
}finally{foreach($ids as $id)$pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$id]);}
