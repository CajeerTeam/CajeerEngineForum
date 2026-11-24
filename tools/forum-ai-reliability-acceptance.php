#!/usr/bin/env php
<?php
/** Real SQL/PDO acceptance for Forum 1.3.11; only the OpenAI transport is mocked.
 * This script fails if the selected PDO driver or Composer dependencies are missing.
 */
declare(strict_types=1);
use CajeerEngine\AI\{AiSettings,OpenAiClient,AiProviderException};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumAiAdmission};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))
    throw new RuntimeException('Required real PDO driver is unavailable: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_analytics');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);
$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    $pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active")');
    foreach(['0075_engine_ai_cost_control.sql','0084_forum_ai_chat.sql','0085_forum_thread_ai.sql','0086_forum_ai_search.sql','0090_forum_ai_privacy_abuse.sql','0091_forum_ai_reliability.sql'] as $name)
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$name));
} else {
    $pdo=$db->connection();
    $database=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($database!=='forum_analytics')throw new RuntimeException('Refuse non-disposable database '.$database);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
final class ReliabilityProvider extends OpenAiClient {
    public int $calls=0;
    public array $lastInput=[];
    public ?AiProviderException $failure=null;
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array {
        ++$this->calls;$this->lastInput=$input;if($this->failure!==null)throw $this->failure;
        return ['text'=>'ok','usage'=>['input_tokens'=>12,'output_tokens'=>4,'cached_input_tokens'=>0],'response_id'=>'resp_reliability'];
    }
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";};
$reject=static function(callable $fn,int $status)use($check):void{try{$fn();}catch(ForumException $e){$check($e->status===$status,'admission rejection '.$status);return;}throw new RuntimeException('Expected rejection');};
$users=[];for($i=0;$i<6;$i++){$id=sprintf('dd111111-1111-4111-8111-%012d',$i);$users[]=$id;$pdo->prepare('INSERT INTO ce_users(id,email,name,password_hash) VALUES (?,?,?,?)')->execute([$id,"reliability-$i@example.invalid","Reliability $i",'!test']);}
$temp=sys_get_temp_dir().'/forum-ai-reliability-'.bin2hex(random_bytes(8));mkdir($temp,0700,true);$old=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-ai-reliability-acceptance');
$gate=new ForumAiAdmission($db);$tickets=[];
try{
    foreach(array_slice($users,0,4) as $u)$tickets[]=$gate->acquire($u);
    $check(count($tickets)===4,'four active tickets');
    $reject(fn()=>$gate->acquire($users[0]),429);
    $reject(fn()=>$gate->acquire($users[4]),429);
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission')->fetchColumn()===4,'timed-out request removed');
    $gate->release(array_pop($tickets));$tickets[]=$gate->acquire($users[4]);
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active=1')->fetchColumn()===4,'released capacity reused');
    foreach($tickets as $t)$gate->release($t);$tickets=[];
    $gate->cooldown(30);$reject(fn()=>$gate->acquire($users[5]),503);
    $pdo->exec('UPDATE ce_forum_ai_admission_state SET cooldown_until=0 WHERE id=1');
    $ticket=$gate->acquire($users[5]);$pdo->prepare('UPDATE ce_forum_ai_admission SET expires_at=? WHERE id=?')->execute([time()-1,$ticket]);
    $check($gate->cleanup()===1,'crashed admission recovered');
    $provider=new ReliabilityProvider();$chat=new ForumAiChat($db,new AiSettings($temp),$provider);
    $current=$chat->administrator();$chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,'daily_request_limit'=>30,'monthly_token_limit'=>100000,'revision'=>$current['revision']]);
    $u=$users[5];$provider->failure=new AiProviderException('uncertain delivery',503,true,0);
    try{$chat->send($u,null,'test uncertain');throw new RuntimeException('Expected provider failure');}catch(AiProviderException){$check(true,'uncertain delivery surfaced');}
    $q=$pdo->prepare("SELECT COUNT(*) FROM ce_ai_cost_events WHERE user_id=? AND state='unverified'");$q->execute([$u]);$check((int)$q->fetchColumn()===1,'ambiguous delivery charged once as unverified');
    $provider->failure=new AiProviderException('rejected',429,false,30);
    try{$chat->send($u,null,'test rejected');throw new RuntimeException('Expected provider failure');}catch(AiProviderException){$check(true,'known rejection surfaced');}
    $q->execute([$u]);$check((int)$q->fetchColumn()===1,'definitive rejection not charged');
    $reject(fn()=>$chat->send($u,null,'cooldown'),503);$check($provider->calls===2,'cooldown avoids provider call');
    $pdo->exec('UPDATE ce_forum_ai_admission_state SET cooldown_until=0 WHERE id=1');$provider->failure=null;
    $result=$chat->send($u,null,'normal response');$id=$result['conversation_id'];
    $check(count($chat->messages($u,$id))===2,'normal chat resumes');
    $insert=$pdo->prepare('INSERT INTO ce_forum_ai_messages (id,conversation_id,role,content,created_at) VALUES (?,?,?,?,?)');
    $before=(int)(microtime(true)*1000000)-10000000;
    for($i=0;$i<14;$i++)$insert->execute([sprintf('aa222222-2222-4222-8222-%012d',$i),$id,$i%2===0?'user':'assistant',str_repeat('x',4000),$before+$i]);
    $chat->send($u,$id,'Latest question');
    $last=$provider->lastInput[array_key_last($provider->lastInput)];
    $check($last['content']==='Latest question' && $provider->lastInput[0]['role']==='system','latest input and system retained');
    $check(strlen(json_encode($provider->lastInput,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=30000 && count($provider->lastInput)<18,'older history trimmed to context cap');

    $month=gmdate('Y-m');$pdo->prepare('UPDATE ce_forum_ai_usage SET reserved_tokens=reserved_tokens+40 WHERE user_id=? AND period=?')->execute([$u,$month]);
    $pdo->prepare('UPDATE ce_forum_ai_conversations SET busy_until=?,reserved_tokens=40,reservation_period=? WHERE id=?')->execute([time()-1,$month,$id]);
    $check(ForumAiChat::recoverExpired($db,100,$u)===1,'expired token lease recovered');
    $check(ForumAiChat::recoverExpired($db,100,$u)===0,'recovery idempotent');
    $q=$pdo->prepare('SELECT reserved_tokens FROM ce_forum_ai_usage WHERE user_id=? AND period=?');$q->execute([$u,$month]);$check((int)$q->fetchColumn()===0,'no stuck token reserve');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission')->fetchColumn()===0,'no leaked admission tickets');
    echo "Forum AI reliability ($driver): $n PASS\n";
}finally{
    foreach($tickets as $t)$gate->release($t);
    $pdo->exec('UPDATE ce_forum_ai_admission_state SET cooldown_until=0 WHERE id=1');
    foreach($users as $u)$pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$u]);
    if($old===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$old);
    foreach(glob($temp.'/storage/app/ai/*')?:[] as $f)@unlink($f);
    @rmdir($temp.'/storage/app/ai');@rmdir($temp.'/storage/app');@rmdir($temp.'/storage');@rmdir($temp);
}
