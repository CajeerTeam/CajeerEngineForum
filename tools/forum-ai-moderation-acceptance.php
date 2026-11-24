#!/usr/bin/env php
<?php
/** Real SQL/OpenAI-boundary test. The only mocked component is outbound HTTP. */
declare(strict_types=1);

use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumTransaction,ForumModerationAdvisor};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);require_once $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Real PDO driver required: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_analytics');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec((string)file_get_contents($file));
}else{
    $pdo=$db->connection();$actual=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($actual!=='forum_analytics')throw new RuntimeException('Unsafe DB target '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
final class ModerationAdviceProvider extends OpenAiClient{
    public int $calls=0;public array $input=[];
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $delta=null):array{
        if($key!=='sk-ai-moderation-acceptance-key')throw new RuntimeException('Incorrect API key');
        $this->input=$input;++$this->calls;
        return ['text'=>json_encode(['risk'=>'high','recommendation'=>'review','flags'=>['spam'],
            'rationale'=>'Содержимое похоже на рекламу, требуется проверка.','evidence'=>['вредная ссылка']],JSON_UNESCAPED_UNICODE),
            'usage'=>['input_tokens'=>50,'cached_input_tokens'=>0,'output_tokens'=>25],'response_id'=>'resp_moderation_stub'];
    }
}
$moderator='6cc617f3-51f3-436d-ad6f-06ba3ce671e9';$member='3a9eafad-2c3f-4662-b894-8fbd67d9acc8';
$thread='42ae0b14-11a9-4585-a242-e78c430cd9d5';$post='a96abe7e-0a0d-40e2-a1cf-e8553d2bc628';$report='ffdab45c-23fd-492c-b329-4a6b05095185';
$checks=0;$check=static function(bool $ok,string $msg)use(&$checks):void{if(!$ok)throw new RuntimeException('FAILED: '.$msg);++$checks;};
$reject=static function(callable $fn,int $status,string $msg)use($check):void{try{$fn();}catch(ForumException $e){$check($e->status===$status,$msg);return;}throw new RuntimeException('Accepted invalid request: '.$msg);};
$tmp=sys_get_temp_dir().'/forum-mod-advice-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
$before=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-ai-moderation-acceptance-key');
try {
    $board=(string)$pdo->query('SELECT id FROM ce_forum_boards ORDER BY id LIMIT 1')->fetchColumn();
    $moderatorRole=(string)$pdo->query("SELECT id FROM ce_roles WHERE handle='forum-moderator'")->fetchColumn();
    $memberRole=(string)$pdo->query("SELECT id FROM ce_roles WHERE handle='forum-member'")->fetchColumn();
    foreach([[$moderator,$moderatorRole,'moderator'],[$member,$memberRole,'member']] as [$uid,$role,$name]){
        $pdo->prepare('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)')->execute([$uid,$uid.'@example.invalid',$name,'!']);
        $pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)')->execute([$uid,$role]);
    }
    $pdo->prepare('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)')->execute([$thread,$board,$member,'Обсуждение жалобы']);
    $pdo->prepare('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)')->execute([$post,$thread,$member,'Здесь вредная ссылка и contact@example.invalid',1,1]);
    $pdo->prepare('INSERT INTO ce_forum_reports(id,post_id,user_id,reason,category,snapshot_body,snapshot_format) VALUES(?,?,?,?,?,?,?)')
        ->execute([$report,$post,$member,'Подозрение на спам','spam','Здесь вредная ссылка','plain']);
    $client=new ModerationAdviceProvider();$ai=new AiSettings($tmp);$chat=new ForumAiChat($db,$ai,$client);
    $tx=new ForumTransaction($db,new AuditLogRepository($db));$service=new ForumModerationAdvisor($db,$tx,$chat);
    $settings=$chat->administrator();$chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,
        'daily_request_limit'=>20,'monthly_token_limit'=>150000,'revision'=>$settings['revision']]);
    $mod=new ForumAccessPolicy($moderator,['forum:moderate','forum:write']);$user=new ForumAccessPolicy($member,['forum:write']);
    $reject(fn()=> $service->get($user,'report',$report),403,'member denied');
    $check($service->get($mod,'report',$report)['available']===false,'no automatic AI request');
    $reject(fn()=> $service->assess($mod,'report',$report,false,false),422,'external disclosure mandatory');
    $check($client->calls===0,'no cost before confirmation');
    $result=$service->assess($mod,'report',$report,true,false);
    $check($client->calls===1&&$result['assessment']['risk']==='high','real billed OpenAI boundary and classification');
    $check($result['assessment']['evidence']===['вредная ссылка'],'grounded quote');
    $check($result['advisory_only']===true,'advisory never applies sanctions');
    $check($service->get($mod,'report',$report)['available']===true,'cached recommendation');
    $again=$service->assess($mod,'report',$report,true,false);
    $check($again['cached']===true&&$client->calls===1,'cached call is not double billed');
    $input=json_encode($client->input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $check(str_contains($input,'вредная ссылка')&&!str_contains($input,'contact@example.invalid'),'redacts private email');
    $check(!str_contains($client->input[0]['content'],'вредная ссылка') && str_contains($client->input[0]['content'],'AI-советник модератора'),'untrusted source not system instructions');
    $check($pdo->query("SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE purpose='search'")->fetchColumn()==0,'temporary transcript deleted');
    $check((string)$pdo->query('SELECT status FROM ce_forum_reports WHERE id=\''.$report.'\'')->fetchColumn()==='open','no automatic sanctions or resolution');
    $pdo->prepare('UPDATE ce_forum_posts SET body=? WHERE id=?')->execute(['Материал изменился и теперь другой',$post]);
    $check($service->get($mod,'report',$report)['available']===false,'edited source invalidates stored recommendation');
    $second=$service->assess($mod,'report',$report,true,false);
    $check($client->calls===2&&$second['available'],'regenerated after edit');
    $pdo->prepare('UPDATE ce_forum_reports SET revision_number=revision_number+1 WHERE id=?')->execute([$report]);
    $check($service->get($mod,'report',$report)['available']===false,'revision invalidation');
    $pdo->prepare("UPDATE ce_forum_reports SET status='resolved' WHERE id=?")->execute([$report]);
    $reject(fn()=> $service->get($mod,'report',$report),409,'closed case rejects AI advice');
    echo "Forum AI Moderation real PDO $driver: $checks PASS\n";
} finally {
    $pdo->prepare('DELETE FROM ce_forum_reports WHERE id=?')->execute([$report]);
    $pdo->prepare('DELETE FROM ce_forum_threads WHERE id=?')->execute([$thread]);
    $pdo->prepare('DELETE FROM ce_users WHERE id IN (?,?)')->execute([$moderator,$member]);
    if($before===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$before);
    foreach(glob($tmp.'/storage/app/ai/*')?:[] as $file)@unlink($file);
    @rmdir($tmp.'/storage/app/ai');@rmdir($tmp.'/storage/app');@rmdir($tmp.'/storage');@rmdir($tmp);
}
