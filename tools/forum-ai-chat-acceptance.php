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
use CajeerEngine\Forum\Service\ForumAiChat;
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
    $pdo->exec('CREATE TABLE ce_users (id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, password_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active")');
    foreach (['0075_engine_ai_cost_control.sql','0084_forum_ai_chat.sql','0085_forum_thread_ai.sql','0086_forum_ai_search.sql','0090_forum_ai_privacy_abuse.sql'] as $name) {
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$name));
    }
} else {
    $pdo = $db->connection();
    $actual = (string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if ($actual !== 'forum_analytics') throw new RuntimeException('Refusing to modify non-disposable database: '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}

final class ForumAiAcceptanceProvider extends OpenAiClient
{
    public int $calls = 0;
    public bool $fail = false;
    public array $lastInput = [];

    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array
    {
        $this->calls++;
        $this->lastInput=$input;
        if ($this->fail) throw new AiProviderException('Injected provider failure.',503);
        if ($key !== 'sk-forum-ai-acceptance-not-a-real-key') throw new RuntimeException('Wrong key source');
        if ($model !== 'gpt-4.1-mini' || $maxOutput<128) throw new RuntimeException('Incorrect provider configuration');
        if ($onDelta!==null){$onDelta('Тестовый ');$onDelta('ответ');}
        return ['text'=>'Тестовый ответ','usage'=>['input_tokens'=>20,'cached_input_tokens'=>0,'output_tokens'=>10],'response_id'=>'resp_test_1'];
    }
}
$assertions = 0;
$check=static function(bool $ok,string $name)use(&$assertions):void {
    if (!$ok) throw new RuntimeException('Forum AI acceptance FAILED: '.$name);
    $assertions++;echo 'PASS '.$name."\n";
};
$expect=static function(callable $run,int $status,string $name)use($check):void {
    try {$run();}catch(ForumException $e){$check($e->status===$status,$name);return;}
    throw new RuntimeException('Expected ForumException: '.$name);
};
$userA='de540148-2730-40de-bbe7-14b149c0aa11';
$userB='5cfd728f-4d23-43a2-85e8-2c3b196fbb22';
foreach ([$userA,$userB] as $n=>$id) {
    $pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash) VALUES (?,?,?,?)')->execute([$id,'forum-ai-acceptance-'.$n.'@example.invalid','Forum AI acceptance '.($n+1),'!test_only']);
}
$temp = sys_get_temp_dir().'/forum-ai-acceptance-'.bin2hex(random_bytes(8));
if (!mkdir($temp,0700,true)) throw new RuntimeException('Unable to create test AI settings directory');
$previousKey=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-forum-ai-acceptance-not-a-real-key');
try {
    $ai = new AiSettings($temp);
    $provider = new ForumAiAcceptanceProvider();
    $chat = new ForumAiChat($db,$ai,$provider);
    $check($chat->administrator()['enabled']===false,'disabled by default');
    $expect(fn()=> $chat->send($userA,null,'Проверка'),403,'disabled rejects provider calls');
    $check($provider->calls===0,'provider was never called while disabled');
    $initial=$chat->administrator();
    $settings=['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,'daily_request_limit'=>3,'monthly_token_limit'=>8000,'revision'=>$initial['revision']];
    $configured=$chat->configure($settings);
    $check($configured['enabled']===true && $configured['revision']===$initial['revision']+1,'admin enables real chat with optimistic revision');
    $expect(fn()=> $chat->configure($settings),409,'stale ACP revision rejected');
    $expect(fn()=> $chat->configure($settings+['invalid'=>true]),422,'unknown ACP settings rejected');
    $delta='';
    $first=$chat->send($userA,null,'Первый вопрос',static function(string $s)use(&$delta):void{$delta.=$s;});
    $id=$first['conversation_id'];
    $check($delta==='Тестовый ответ' && $first['text']==='Тестовый ответ','real streaming provider callback and final response');
    $check(count($chat->messages($userA,$id))===2,'both roles persisted in SQL');
    $check(count($chat->conversations($userA))===1,'owner can list their conversation');
    $check(count($chat->conversations($userB))===0,'second user cannot list others conversations');
    $expect(fn()=> $chat->messages($userB,$id),404,'cross-user history access denied');
    $expect(fn()=> $chat->rename($userB,$id,'Чужой чат'),404,'cross-user rename denied');
    $expect(fn()=> $chat->delete($userB,$id),404,'cross-user delete denied');
    $response=$chat->send($userA,$id,'Второй вопрос');
    $check($response['conversation_id']===$id && count($chat->messages($userA,$id))===4,'second turn preserves conversation and history');
    $check(count($provider->lastInput)>=4,'history passed into real OpenAI payload');
    $pdo->prepare('UPDATE ce_forum_ai_conversations SET busy_until=? WHERE id=?')->execute([time()+45,$id]);
    $expect(fn()=> $chat->send($userA,$id,'Параллельный вопрос'),409,'concurrent generation lease denies second request');
    $pdo->prepare('UPDATE ce_forum_ai_conversations SET busy_until=0 WHERE id=?')->execute([$id]);
    $renamed=$chat->rename($userA,$id,'Мои вопросы');
    $check($renamed['title']==='Мои вопросы','title update persisted');
    $third=$chat->send($userA,$id,'Третий вопрос');
    $check(count($chat->messages($userA,$id))===6,'third response persisted');
    $expect(fn()=> $chat->send($userA,$id,'Четвёртый вопрос'),429,'daily request hard quota enforced');
    $status=$chat->status($userA);
    $check($status['daily_requests_used']===3 && $status['monthly_tokens_used']===90 && $status['monthly_tokens_reserved']===0,'used vs reserved tokens and daily usage precise');
    $billed=(int)$pdo->query("SELECT COUNT(*) FROM ce_ai_cost_events WHERE module='forum_chat' AND state='measured'")->fetchColumn();
    $check($billed===3,'shared monetary ledger settled three real generations');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()===0,'no monetary leases remain');
    $next=$chat->administrator();
    $chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,'daily_request_limit'=>3,'monthly_token_limit'=>1000,'revision'=>$next['revision']]);
    $before=$provider->calls;
    $expect(fn()=> $chat->send($userB,null,str_repeat('Д',1300)),429,'monthly token limit blocks expensive request before external call');
    $check($provider->calls===$before && count($chat->conversations($userB))===0,'token quota rollback leaves no phantom conversation');
    $provider->fail=true;
    try {$chat->send($userB,null,'Заведомый сбой');throw new RuntimeException('Expected injected provider failure');}
    catch(AiProviderException){$check(true,'provider failure propagated without secret');}
    $check(count($chat->conversations($userB))===0 && (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()===0,'provider failure rolls back conversation and money reservation');
    $provider->fail=false;
    $chat->delete($userA,$id);
    $check(count($chat->conversations($userA))===0,'delete removes owner conversation');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_messages')->fetchColumn()===0,'delete removes all child messages');
    echo "Forum AI chat acceptance: {$assertions} PASS ({$driver}).\n";
} finally {
    $pdo->prepare('DELETE FROM ce_users WHERE id IN (?,?)')->execute([$userA,$userB]);
    if ($previousKey===false) putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$previousKey);
    foreach (glob($temp.'/storage/app/ai/*')?:[] as $f) @unlink($f);
    @rmdir($temp.'/storage/app/ai');@rmdir($temp.'/storage/app');@rmdir($temp.'/storage');@rmdir($temp);
}
