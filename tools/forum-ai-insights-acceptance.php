#!/usr/bin/env php
<?php
/** Real Forum DB permissions, analytics rollups and billed AI chat; only outbound OpenAI HTTP is stubbed. */
declare(strict_types=1);

use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumAiInsights,ForumTransaction,ForumAnalyticsRollup};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);require_once $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Real PDO required: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_ai_insights');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec((string)file_get_contents($file));
}else{
    $pdo=$db->connection();
    $name=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($name!=='forum_ai_insights')throw new RuntimeException('Refusing unsafe DB '.$name);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
final class InsightsStubProvider extends OpenAiClient {
    public int $calls=0;public array $input=[];
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $delta=null):array {
        if($key!=='sk-forum-insights-acceptance-key')throw new RuntimeException('Provider key mismatch');
        $this->calls++;$this->input=$input;
        return ['text'=>json_encode(['headline'=>'Активность форума увеличилась','observations'=>[
            ['kind'=>'observation','text'=>'Число просмотров превышает предыдущий период.','evidence_ids'=>['events.views','previous.views']],
            ['kind'=>'caveat','text'=>'Данные отражают события, а не уникальных посетителей.','evidence_ids'=>['events.total']]]],JSON_UNESCAPED_UNICODE),
            'usage'=>['input_tokens'=>110,'cached_input_tokens'=>0,'output_tokens'=>45],'response_id'=>'resp_insights_fake'];
    }
}
$checkCount=0;
$check=static function(bool $value,string $message)use(&$checkCount):void{if(!$value)throw new RuntimeException($message);$checkCount++;};
$reject=static function(callable $fn,int $status,string $label)use($check):void{
    try{$fn();}catch(ForumException $e){$check($e->status===$status,$label);return;}
    throw new RuntimeException('Invalid request accepted: '.$label);
};
$user='f29c484b-a25d-45bd-aa07-e4630862dfab';$outsider='0e42144e-3e07-41a8-93c7-9ff439c966c5';
$tmp=sys_get_temp_dir().'/forum-ai-insights-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
$priorKey=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-forum-insights-acceptance-key');
try {
    $role=(string)$pdo->query("SELECT id FROM ce_roles WHERE handle='forum-acp-settings'")->fetchColumn();
    if($role==='')throw new RuntimeException('Forum ACP settings role absent');
    foreach([$user,$outsider] as $uid)$pdo->prepare("INSERT INTO ce_users (id,email,name,password_hash,two_factor_recovery_codes) VALUES (?,?,?,?,'[]')")->execute([$uid,$uid.'@example.invalid','insights','!']);
    $pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)')->execute([$user,$role]);
    $now=intdiv(time(),86400)*86400;
    $pdo->prepare('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?)')
        ->execute([$now-3*86400,'forum_thread_view',20,time()]);
    $pdo->prepare('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES(?,?,?,?)')
        ->execute([$now-10*86400,'forum_thread_view',5,time()]);
    $pdo->prepare('INSERT INTO ce_forum_analytics_events(id,event_name,created_at,aggregated_at) VALUES(?,?,?,NULL)')
        ->execute(['103a57d94b1b41ef9e1b642d065f3891','forum_thread_view',$now-2*86400]);
    $provider=new InsightsStubProvider();$ai=new AiSettings($tmp);$chat=new ForumAiChat($db,$ai,$provider);
    $settings=$chat->administrator();$chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>384,
        'daily_request_limit'=>20,'monthly_token_limit'=>150000,'revision'=>$settings['revision']]);
    $service=new ForumAiInsights($db,new ForumTransaction($db,new AuditLogRepository($db)),$chat);
    $admin=new ForumAccessPolicy($user,['forum:acp:settings']);$guest=new ForumAccessPolicy($outsider,['forum:acp:settings']);
    $from=gmdate('Y-m-d',$now-5*86400);$until=gmdate('Y-m-d',$now);
    $reject(fn()=> $service->preview($guest,$from,$until),403,'role denied by live DB');
    $report=$service->preview($admin,$from,$until);
    $map=array_column($report['facts'],'value','id');
    $check($map['events.views']===21,'rollup plus live pending without loss');
    $check($map['previous.views']===5,'previous retained period');
    $check($provider->calls===0,'read-only preview has no AI cost');
    $reject(fn()=> $service->explain($admin,$from,$until,'total',false),422,'explicit consent');
    $check($provider->calls===0,'no AI before consent');
    $reject(fn()=> $service->preview($admin,$from,null),422,'strict date range');
    $reject(fn()=> $service->preview($admin,$from,$until,'event:unlisted'),422,'unknown metric');
    $result=$service->explain($admin,$from,$until,'total',true);
    $check($provider->calls===1,'one billed provider call');
    $check($result['advisory_only']===true&&count($result['observations'])===2,'bounded advisory');
    $check($result['observations'][0]['evidence'][0]['value']===21,'server-verified evidence');
    $input=json_encode($provider->input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $check(!str_contains($input,$user)&&!str_contains($input,$outsider)&&!str_contains($input,'@example.invalid'),'no identifiers or emails sent');
    $check(str_contains($provider->input[0]['content'],'аналитический советник')
        &&!str_contains($provider->input[0]['content'],'forum_thread_view'),'untrusted metrics never in system role');
    $check((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE purpose='search'")->fetchColumn()===0,'erase transient AI transcript');
    $usage=$pdo->prepare('SELECT used_tokens FROM ce_forum_ai_usage WHERE user_id=?');$usage->execute([$user]);
    $check((int)$usage->fetchColumn()>=155,'token usage recorded');
    (new ForumAnalyticsRollup($db))->aggregateBatch(1000);
    $after=$service->preview($admin,$from,$until);
    $check(array_column($after['facts'],'value','id')['events.views']===21,'worker does not duplicate count');
    $reject(fn()=> $service->explain($guest,$from,$until,'total',true),403,'unauthorized provider denied');
    $check($provider->calls===1,'no charge for denied access');
    echo "Forum AI Insights real PDO $driver: $checkCount PASS\n";
} finally {
    $pdo->prepare('DELETE FROM ce_users WHERE id IN (?,?)')->execute([$user,$outsider]);
    if($priorKey===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$priorKey);
    foreach(glob($tmp.'/storage/app/ai/*')?:[] as $file)@unlink($file);
    @rmdir($tmp.'/storage/app/ai');@rmdir($tmp.'/storage/app');@rmdir($tmp.'/storage');@rmdir($tmp);
}
