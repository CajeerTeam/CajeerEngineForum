#!/usr/bin/env php
<?php
/**
 * Real-PDO Forum 1.3.1 acceptance on disposable SQLite / forum_analytics PG/MySQL.
 * The only mocked boundary is OpenAI HTTP; SQL, budgets, leases and chat are real.
 * Requires vendor/autoload.php and driver-specific PDO extension; fails, never skips.
 */
declare(strict_types=1);

use CajeerEngine\AI\{AiProviderException,AiSettings,OpenAiClient};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumWritingAssistant};
use CajeerEngine\Runtime\ConfigRepository;

$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
$driver = getenv('FORUM_TEST_DRIVER') ?: 'sqlite';
if (!in_array($driver,['sqlite','pgsql','mysql'],true) || !in_array($driver,PDO::getAvailableDrivers(),true)) {
    throw new RuntimeException('Real PDO driver required for Forum AI acceptance: '.$driver);
}
if ($driver !== 'sqlite') {
    putenv('DB_DRIVER='.$driver);
    putenv('DB_DATABASE=forum_analytics');
}
$config = new ConfigRepository($root);
$config->set('database.default',$driver);
$db = new DatabaseManager($config);
if ($driver === 'sqlite') {
    $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    $pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, password_hash TEXT NOT NULL)');
    foreach (['0075_engine_ai_cost_control.sql','0084_forum_ai_chat.sql','0085_forum_thread_ai.sql','0086_forum_ai_search.sql'] as $name) {
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$name));
    }
} else {
    $pdo = $db->connection();
    $actual = (string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if ($actual !== 'forum_analytics') throw new RuntimeException('Refusing to modify non-disposable database: '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}

final class WritingProvider extends OpenAiClient
{
    public int $calls=0;
    public bool $fail=false;
    public array $lastInput=[];
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $delta=null):array
    {
        ++$this->calls;$this->lastInput=$input;
        if($this->fail)throw new AiProviderException('Mock outage',503);
        if($key!=='sk-forum-writing-acceptance-not-real')throw new RuntimeException('Invalid key');
        return ['text'=>'Исправленный текст', 'usage'=>['input_tokens'=>20,'cached_input_tokens'=>0,'output_tokens'=>10], 'response_id'=>'resp_mock'];
    }
}
$checks=0;
$ok=static function(bool $condition,string $reason)use(&$checks):void{if(!$condition)throw new RuntimeException($reason);++$checks;};
$uid='78d0ed19-a173-466c-a499-f3fb181daf9e';
$pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash) VALUES (?,?,?,?)')->execute([$uid,'writing-acceptance@example.invalid','AI writing user','!test']);
$temp=sys_get_temp_dir().'/forum-writing-'.bin2hex(random_bytes(8));
if(!mkdir($temp,0700,true))throw new RuntimeException('mkdir failed');
$prior=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-forum-writing-acceptance-not-real');
try{
    $ai=new AiSettings($temp);$provider=new WritingProvider();
    $chat=new ForumAiChat($db,$ai,$provider);$writing=new ForumWritingAssistant($chat);
    $payload=['mode'=>'fix','body'=>'Исходный черновик','title'=>'Название','body_format'=>'bbcode'];
    try{$writing->suggest($uid,$payload);throw new RuntimeException('Disabled AI accepted');}
    catch(ForumException $e){$ok($e->status===403,'disabled AI');}
    $ok($provider->calls===0,'no call while disabled');
    $status=$chat->administrator();
    $chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,
        'daily_request_limit'=>2,'monthly_token_limit'=>10000,'revision'=>$status['revision']]);
    $result=$writing->suggest($uid,$payload);
    $ok($result['text']==='Исправленный текст'&&$result['mode']==='fix'&&$provider->calls===1,'real provider boundary');
    $ok(str_contains($provider->lastInput[0]['content'],'AI-редактор')&&!str_contains($provider->lastInput[0]['content'],'Исходный черновик'),'trusted system role excludes draft');
    $ok(str_contains($provider->lastInput[1]['content'],'Исходный черновик'),'draft passed only as user context');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_conversations')->fetchColumn()===0,'temporary conversation removed');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_messages')->fetchColumn()===0,'draft and model response erased');
    $ok(count($chat->conversations($uid))===0,'generic AI history unaffected');
    $ok($chat->status($uid)['monthly_tokens_used']===30,'shared token ledger charged');
    $title=$payload;$title['mode']='title';$writing->suggest($uid,$title);
    $ok($provider->calls===2,'title uses same provider');
    try{$writing->suggest($uid,$payload);throw new RuntimeException('Daily limit bypassed');}
    catch(ForumException $e){$ok($e->status===429,'daily quota enforced');}
    $ok($provider->calls===2,'no extra provider call past quota');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()===0,'no financial reservations remain');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_conversations')->fetchColumn()===0,'no failed request persistence');
    $other='165bf113-3b3f-40c9-9123-f0280b475a8e';
    $pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash) VALUES (?,?,?,?)')->execute([$other,'writing-other@example.invalid','Other','!test']);
    $provider->fail=true;
    try{$writing->suggest($other,$payload);throw new RuntimeException('Failure not thrown');}
    catch(AiProviderException){$ok(true,'provider failure propagated');}
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_conversations')->fetchColumn()===0,'provider failure SQL rollback');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()===0,'provider failure budget rollback');
    $pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$other]);
    echo "Forum AI writing real-PDO acceptance: $checks PASS ($driver)\n";
}finally{
    $pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$uid]);
    if($prior===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$prior);
    foreach(glob($temp.'/storage/app/ai/*')?:[] as $f)@unlink($f);
    @rmdir($temp.'/storage/app/ai');@rmdir($temp.'/storage/app');@rmdir($temp.'/storage');@rmdir($temp);
}
