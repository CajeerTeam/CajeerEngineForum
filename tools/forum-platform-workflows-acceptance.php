<?php
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository,AuditLogRepository};
use CajeerEngine\Forum\Service\{PlatformWorkflows,ForumSupportAssistant,ForumAiChat,ForumAiSearch,ForumTransaction};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Real PDO required');
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_analytics');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA foreign_keys=ON');(new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec(file_get_contents($file));}
else{$pdo=$db->connection();if($pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn()!=='forum_analytics')throw new RuntimeException('Unsafe database');(new MigrationRunner($pdo,$root.'/migrations'))->run($driver);}
$roles=new RoleRepository($db);$roles->seedDefaults();$users=new UserRepository($db,$roles);$ids=[];
foreach(['admin','operator','member'] as $key){$id=Uuid::v4();$pdo->prepare('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)')->execute([$id,$id.'@example.invalid',$key,'!']);$ids[$key]=$id;}
$a=$ids['admin'];$o=$ids['operator'];$m=$ids['member'];$users->syncRoles($a,['admin']);$users->syncRoles($o,['forum-acp-members']);$users->syncRoles($m,['forum-member']);
$svc=new PlatformWorkflows($db);$tx=new ForumTransaction($db,new AuditLogRepository($db));$search=new ForumAiSearch($db,$tx,new ForumAiChat($db,new AiSettings($root),new OpenAiClient()));$support=new ForumSupportAssistant($db,$search);$member=new ForumAccessPolicy($m,['forum:write']);$n=0;$ruleIds=[];
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";$n++;};
$reject=static function(callable $f,int $status)use($check):void{try{$f();}catch(ForumException $e){$check($e->status===$status,'rejected '.$status);return;}throw new RuntimeException('Expected rejection');};
$scalar=static function(string $sql,array $args=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchColumn();};
$base=['id'=>null,'revision'=>0,'name'=>'Route support','trigger_name'=>'support.requested','action_name'=>'support.assign','operator_id'=>$o,'condition_value'=>0,'interval_seconds'=>0,'enabled'=>true];
$getRule=static fn(array $data,string $name)=>array_values(array_filter($data['rules'],static fn(array $r)=>$r['name']===$name))[0];
try{
 $reject(fn()=>$svc->save($m,$base),403);$reject(fn()=>$svc->save($a,array_replace($base,['operator_id'=>$m])),422);
 $r=$getRule($svc->save($a,$base),'Route support');$ruleIds[]=$r['id'];
 $ticket=$support->create($member,['subject'=>'Need help','message'=>'Please help with account','ai_help'=>false])['ticket']['id'];
 $check((int)$scalar('SELECT COUNT(*) FROM ce_platform_workflow_events WHERE rule_id=? AND ticket_id=?',[$r['id'],$ticket])===1,'real support creation transaction emits event');
 $db->transaction(fn()=>$svc->supportRequested($ticket));$check((int)$scalar('SELECT COUNT(*) FROM ce_platform_workflow_events WHERE rule_id=? AND ticket_id=?',[$r['id'],$ticket])===1,'duplicate capture coalesced');
 $check($svc->preview($a,$base)['eligible']>=1&&$scalar('SELECT assigned_to FROM ce_forum_support_tickets WHERE id=?',[$ticket])===null,'preview is read-only');
 $svc->drain();$check($scalar('SELECT assigned_to FROM ce_forum_support_tickets WHERE id=?',[$ticket])===$o,'actual operator assignment');
 $svc->drain();$check((int)$scalar('SELECT revision FROM ce_forum_support_tickets WHERE id=?',[$ticket])===2,'repeated worker no second assignment');
 $check((int)$scalar("SELECT COUNT(*) FROM ce_platform_workflow_runs WHERE rule_id=? AND status='done'",[$r['id']])===1,'durable execution history');
 $ticket2=$support->create($member,['subject'=>'Second request','message'=>'Another question','ai_help'=>false])['ticket']['id'];
 $changed=array_replace($base,['id'=>$r['id'],'revision'=>(int)$r['revision'],'enabled'=>false]);$svc->save($a,$changed);$reject(fn()=>$svc->save($a,$changed),409);
 $svc->drain();$check($scalar('SELECT assigned_to FROM ce_forum_support_tickets WHERE id=?',[$ticket2])===null,'disabled revision does not run');
 $schedule=array_replace($base,['name'=>'Scheduled route','trigger_name'=>'schedule','interval_seconds'=>60,'condition_value'=>30]);$sr=$getRule($svc->save($a,$schedule),'Scheduled route');$ruleIds[]=$sr['id'];
 $now=(int)$sr['next_due']+600;$svc->drain(10,$now);$check($scalar('SELECT assigned_to FROM ce_forum_support_tickets WHERE id=?',[$ticket2])===$o,'missed schedule caught up');
 $check((int)$scalar('SELECT next_due FROM ce_platform_workflows WHERE id=?',[$sr['id']])===$now+60,'missed intervals coalesced');
 $ticket3=$support->create($member,['subject'=>'Third request','message'=>'Operator needed','ai_help'=>false])['ticket']['id'];$users->syncRoles($o,['viewer']);$svc->drain(10,$now+61);
 $check($scalar('SELECT assigned_to FROM ce_forum_support_tickets WHERE id=?',[$ticket3])===null&&(int)$scalar('SELECT enabled FROM ce_platform_workflows WHERE id=?',[$sr['id']])===0,'operator revocation pauses rule');
 $ar=$getRule($svc->save($a,array_replace($schedule,['name'=>'Aggregate','action_name'=>'analytics.aggregate','operator_id'=>null,'condition_value'=>1])),'Aggregate');$ruleIds[]=$ar['id'];
 $ev=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)')->execute([$ev,'workflow_acceptance',time()-3600]);
 $svc->drain(10,(int)$ar['next_due']+1);$check($scalar('SELECT aggregated_at FROM ce_forum_analytics_events WHERE id=?',[$ev])!==null,'actual analytics rollup action');
 $cleanup=$getRule($svc->save($a,array_replace($schedule,['name'=>'Cleanup','action_name'=>'analytics.cleanup','operator_id'=>null,'condition_value'=>0])),'Cleanup');$ruleIds[]=$cleanup['id'];
 $old=bin2hex(random_bytes(16));$unapplied=bin2hex(random_bytes(16));$oldTime=time()-100*86400;
 $pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at,aggregated_at) VALUES(?,?,?,?)')->execute([$old,'workflow_acceptance',$oldTime,time()]);
 $pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at) VALUES(?,?,?)')->execute([$unapplied,'workflow_acceptance',$oldTime]);
 // Disable aggregation so the cleanup action must preserve the unapplied record.
 $latest=$getRule($svc->overview($a),'Aggregate');$svc->save($a,array_replace($schedule,['id'=>$latest['id'],'revision'=>(int)$latest['revision'],'name'=>'Aggregate','action_name'=>'analytics.aggregate','operator_id'=>null,'condition_value'=>1,'enabled'=>false]));
 $svc->drain(10,(int)$cleanup['next_due']+1);$check($scalar('SELECT id FROM ce_forum_analytics_events WHERE id=?',[$old])===false&&$scalar('SELECT id FROM ce_forum_analytics_events WHERE id=?',[$unapplied])===$unapplied,'cleanup applies fixed retention without deleting pending raw events');
 $latest=$getRule($svc->overview($a),'Aggregate');$svc->save($a,array_replace($schedule,['id'=>$latest['id'],'revision'=>(int)$latest['revision'],'name'=>'Aggregate','action_name'=>'analytics.aggregate','operator_id'=>null,'condition_value'=>1,'enabled'=>true]));
 $users->syncRoles($a,['viewer']);$svc->drain(10,time()+125);$check((int)$scalar('SELECT enabled FROM ce_platform_workflows WHERE id=?',[$ar['id']])===0,'authorizer revocation pauses workflow');
 $check((int)$scalar("SELECT COUNT(*) FROM ce_audit_log WHERE actor_id=? AND event='platform.workflow.executed'",[$a])>=3,'transactional audit');
 echo "Platform workflows $driver: $n PASS\n";
}finally{
 foreach($ruleIds as $id){foreach(['ce_platform_workflow_events','ce_platform_workflow_runs'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE rule_id=?')->execute([$id]);$pdo->prepare('DELETE FROM ce_platform_workflows WHERE id=?')->execute([$id]);}
 foreach(['ce_forum_analytics_events','ce_forum_analytics_daily'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE event_name=?')->execute(['workflow_acceptance']);
 foreach($ids as $id)$pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$id]);
}
