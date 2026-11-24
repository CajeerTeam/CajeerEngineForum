#!/usr/bin/env php
<?php
/** Paid, opt-in release smoke. Uses the exact extracted distribution, disposable SQL and real OpenAI. */
declare(strict_types=1);

use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Service\ForumAiChat;
use CajeerEngine\Runtime\ConfigRepository;

$root = isset($argv[1]) ? realpath($argv[1]) : false;
if ($argc !== 2 || $root === false || getenv('FORUM_AI_LIVE_ACCEPTANCE') !== 'approved-paid-synthetic-test') {
    fwrite(STDERR,"Explicit paid synthetic test approval and extracted dist path are required.\n");
    exit(1);
}
$key = trim((string)getenv('FORUM_AI_RELEASE_TEST_KEY'));
if ($key === '' || !in_array('sqlite',PDO::getAvailableDrivers(),true) || !function_exists('curl_init') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"Missing dedicated release test key, PDO SQLite, curl or distribution dependencies.\n");
    exit(1);
}
// No key, raw provider response, response IDs, or stack traces are emitted.
ini_set('display_errors','0');
ini_set('zend.exception_ignore_args','1');
require $root.'/vendor/autoload.php';
$previous=getenv('OPENAI_API_KEY');
putenv('OPENAI_API_KEY='.$key);
if(function_exists('sodium_memzero')) sodium_memzero($key);
$stage='initialization';
$exitCode=0;
try {
    $config=new ConfigRepository($root);
    $config->set('database.default','sqlite');
    $db=new DatabaseManager($config);
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    $migrations=glob($root.'/migrations/sqlite/*.sql');
    if(!$migrations)throw new RuntimeException('Missing migrations');
    foreach($migrations as $file)$pdo->exec((string)file_get_contents($file));
    $uid='a1400000-0000-4000-8000-000000000001';
    $pdo->prepare("INSERT INTO ce_users (id,email,name,password_hash,two_factor_recovery_codes) VALUES (?,?,?,?,'[]')")->execute([$uid,'ai-release@example.invalid','Synthetic release test','!']);
    $temp=sys_get_temp_dir().'/forum-ai-live-'.bin2hex(random_bytes(8));
    if(!mkdir($temp,0700,true))throw new RuntimeException('Cannot create disposable directory');
    $ai=new AiSettings($temp);
    // One generation only, with small token and monetary ceilings. No real member data.
    $ai->update(['global_daily_limit_cents'=>10,'user_daily_limit_cents'=>10]);
    $chat=new ForumAiChat($db,$ai,new OpenAiClient());
    $initial=$chat->administrator();
    $chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,'daily_request_limit'=>1,'monthly_token_limit'=>4000,'revision'=>$initial['revision']]);
    $stage='provider streaming and persistence';
    $stream='';
    $result=$chat->send($uid,null,'Reply with the single word READY.',static function(string $delta)use(&$stream):void{$stream.=$delta;});
    if(trim($stream)==='' || $stream!==$result['text'] || $result['usage']['input_tokens']<=0 || $result['usage']['output_tokens']<=0)throw new RuntimeException('Invalid live stream');
    $messages=$chat->messages($uid,$result['conversation_id']);
    if(count($messages)!==2 || $messages[1]['content']!==$result['text'])throw new RuntimeException('History mismatch');
    $stage='billing and admission cleanup';
    $status=$chat->status($uid);
    if($status['daily_requests_used']!==1 || $status['monthly_tokens_reserved']!==0 || $status['monthly_tokens_used']!==$result['usage']['input_tokens']+$result['usage']['output_tokens'])throw new RuntimeException('Token accounting mismatch');
    $q=$pdo->prepare("SELECT COUNT(*) FROM ce_ai_cost_events WHERE user_id=? AND state='measured'");$q->execute([$uid]);
    if((int)$q->fetchColumn()!==1 || (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()!==0 || (int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission')->fetchColumn()!==0)throw new RuntimeException('Cost/admission mismatch');
    $stage='privacy erasure';
    $deleted=$chat->eraseHistory($uid);
    if($deleted['deleted_conversations']!==1 || $deleted['deleted_messages']!==2 || $chat->conversations($uid)!==[])throw new RuntimeException('Erasure mismatch');
    echo "PASS real OpenAI Responses streaming, SQL history, measured billing, admission and privacy erasure (synthetic signed-dist smoke).\n";
} catch (Throwable $e) {
    fwrite(STDERR,"FAIL live AI release acceptance at ".$stage." (".$e::class.")\n");
    $exitCode=1;
} finally {
    if($previous===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$previous);
    if(isset($temp)){
        foreach(glob($temp.'/storage/app/ai/*')?:[] as $file)@unlink($file);
        @rmdir($temp.'/storage/app/ai');@rmdir($temp.'/storage/app');@rmdir($temp.'/storage');@rmdir($temp);
    }
}

exit($exitCode);
