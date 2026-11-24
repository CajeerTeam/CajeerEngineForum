#!/usr/bin/env php
<?php
/** Forum 1.3.5 real-PDO support acceptance; SQL, search, OpenAI budget and operator workflow. */
declare(strict_types=1);

use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumAiSearch,ForumTransaction,ForumSupportAssistant};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql','pgsql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Required real PDO driver: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_analytics');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec((string)file_get_contents($file));
}else{
    $pdo=$db->connection();
    $actual=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($actual!=='forum_analytics')throw new RuntimeException('Refusing non-disposable database '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
final class SearchAcceptanceOpenAi extends OpenAiClient{
    public int $calls=0;public array $input=[];public bool $fail=false;
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $tokens,?callable $onDelta=null):array{
        if($key!=='sk-forum-search-stub-key')throw new RuntimeException('Incorrect key');
        $this->calls++;$this->input=$input;
        if($this->fail)throw new \CajeerEngine\AI\AiProviderException('Injected provider failure',503);
        if($onDelta)$onDelta('По сообщениям [1]');
        return ['text'=>'По сообщениям [1]','usage'=>['input_tokens'=>48,'cached_input_tokens'=>0,'output_tokens'=>22],'response_id'=>'resp_search_stub'];
    }
}
$uid='8e1c90da-1663-4369-a948-a4b697455e52';
$thread='76c242bf-7cdc-48da-890b-119106175c60';
$post='5a93ca27-39e4-4919-bd0e-a7053f121d30';
$deleted='cca6c456-512c-477b-973a-91b82f0665ee';
$checks=0;
$check=static function(bool $ok,string $what)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL AI search: '.$what);++$checks;echo "PASS $what\n";};
$reject=static function(callable $f,int $code,string $what)use($check):void{try{$f();}catch(ForumException $e){$check($e->status===$code,$what);return;}throw new RuntimeException('Expected rejection '.$what);};
$tmp=sys_get_temp_dir().'/forum-ai-search-'.bin2hex(random_bytes(6));
if(!mkdir($tmp,0700,true))throw new RuntimeException('Cannot prepare temporary AI key root');
$before=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-forum-search-stub-key');
try{
    $board=(string)$pdo->query('SELECT id FROM ce_forum_boards ORDER BY id LIMIT 1')->fetchColumn();
    $role=(string)$pdo->query("SELECT id FROM ce_roles WHERE handle='forum-member' LIMIT 1")->fetchColumn();
    if($board===''||$role==='')throw new RuntimeException('Missing seeded forum board/role');
    $pdo->prepare("INSERT INTO ce_users (id,email,name,password_hash,two_factor_recovery_codes) VALUES (?,?,?,?,'[]')")->execute([$uid,'search-acceptance@example.invalid','Search acceptance','!']);
    $pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) VALUES (?,?)')->execute([$uid,$role]);
    $pdo->prepare('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)')->execute([$thread,$board,$uid,'Инструкция криптографии и ключей']);
    $pdo->prepare('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)')->execute([$post,$thread,$uid,'Криптографический ключ хранится отдельно от пользовательского сообщения.',1,1]);
    $pdo->prepare('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)')->execute([$deleted,$thread,$uid,'Криптографический удалённый секрет',2,2]);
    $pdo->prepare('UPDATE ce_forum_posts SET deleted_at=? WHERE id=?')->execute([time(),$deleted]);
    $client=new SearchAcceptanceOpenAi();$ai=new AiSettings($tmp);$chat=new ForumAiChat($db,$ai,$client);
    $tx=new ForumTransaction($db,new AuditLogRepository($db));$search=new ForumAiSearch($db,$tx,$chat);
    $config=$chat->administrator();$chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>128,'daily_request_limit'=>30,'monthly_token_limit'=>200000,'revision'=>$config['revision']]);
    $actor=new ForumAccessPolicy($uid,['forum:write']);
    $reject(fn()=>ForumAiSearch::question(''),422,'empty search query rejected');
    $check(ForumAiSearch::terms('Как хранится криптографический ключ?')!==[],'natural question token extraction');
    $evidence=$search->retrieve($actor,'Криптографический ключ');
    $check(count($evidence['sources'])>=1,'indexed public SQL search found source');
    $first=$evidence['sources'][0];
    $check($first['post_id']===$post,'actual live post is source, not generated text');
    $check($first['url']==='/forum/threads/'.$thread.'?page=1#post-'.$post,'server generated permalink to correct post/page');
    $check(!str_contains(json_encode($evidence,JSON_UNESCAPED_UNICODE),$deleted),'deleted post is not an AI source');
    $check(ForumAiSearch::citations('Ответ [1]',$evidence['sources'])['valid'],'real citation is recognized');
    $check(!ForumAiSearch::citations('Ссылка [99]',$evidence['sources'])['valid'],'fabricated citation rejected');
    // Edits between search and provider generation must invalidate the old
    // exact excerpt, rather than leaking stale evidence into OpenAI context.
    $pdo->prepare('UPDATE ce_forum_posts SET body=? WHERE id=?')->execute([
        'Криптографический ключ перенесён и обновлён; старые цитаты недействительны.',$post
    ]);
    $beforeEditCalls=$client->calls;
    $stale=$search->answer($actor,'Криптографический ключ',null,$evidence);
    $check($stale['no_evidence']===true&&$client->calls===$beforeEditCalls,'edited evidence discarded before external generation');
    $pdo->prepare('UPDATE ce_forum_posts SET body=? WHERE id=?')->execute([
        'Криптографический ключ хранится отдельно от пользовательского сообщения.',$post
    ]);
    $evidence=$search->retrieve($actor,'Криптографический ключ');
    $check(($evidence['sources'][0]['post_id']??null)===$post,'reindexed current revision discovered after edit');
    $deltas='';$answer=$search->answer($actor,'Криптографический ключ',static function(string $s)use(&$deltas):void{$deltas.=$s;},$evidence);
    $check($deltas==='По сообщениям [1]'&&$answer['text']===$deltas&&$answer['citation_check']['valid'],'real provider stream and bounded citation result');
    $input=json_encode($client->input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $check($client->input[1]['role']==='user'&&str_contains($input,'Криптографический ключ'),'retrieved material passes only as untrusted user context');
    $check(!str_contains($input,'удалённый секрет'),'deleted source not sent to external AI');
    $check($chat->conversations($uid)===[],'one-shot search cannot be read as ordinary chat history');
    $check((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_ai_conversations WHERE purpose='search'")->fetchColumn()===0,'search transcripts erased after reply');
    $support=new ForumSupportAssistant($db,$search);
    $operatorId='fa49ec21-677b-46c4-a14c-0c0d4040b321';
    $otherId='bddca85e-91b6-49a6-9b4f-a887837e417b';
    foreach ([$operatorId,$otherId] as $idx=>$person) {
        $pdo->prepare("INSERT INTO ce_users (id,email,name,password_hash,two_factor_recovery_codes) VALUES (?,?,?,?,'[]')")->execute([$person,'support-'.$idx.'@example.invalid','Support acceptance '.($idx+1),'!']);
    }
    $operator=new ForumAccessPolicy($operatorId,['forum:acp:members']);
    $other=new ForumAccessPolicy($otherId,[]);
    $check($support->faq()===[],'empty FAQ returns real published list');
    $reject(fn()=> $support->saveFaq($other,null,['question'=>'Новый вопрос','answer'=>'Публичный ответ','published'=>true,'sort_order'=>1]),403,'non-operator cannot publish FAQ');
    $faq=$support->saveFaq($operator,null,['question'=>'Как хранить ключ?','answer'=>'Храните отдельно от сообщений.','published'=>true,'sort_order'=>1]);
    $check(count($support->faq())===1 && $support->faq()[0]['answer']==='Храните отдельно от сообщений.','published FAQ returned to users');
    $updated=$support->saveFaq($operator,$faq['id'],['question'=>'Где хранить ключ?','answer'=>'В отдельном защищённом хранилище.','published'=>false,'sort_order'=>2,'expected_revision'=>(int)$faq['revision']]);
    $check($support->faq()===[] && (int)$updated['revision']===(int)$faq['revision']+1,'draft FAQ hidden and optimistic revision updated');
    $reject(fn()=> $support->saveFaq($operator,$faq['id'],['question'=>'Дубль','answer'=>'Устаревший ответ','published'=>true,'sort_order'=>1,'expected_revision'=>(int)$faq['revision']]),409,'stale FAQ update rejected');
    $support->deleteFaq($operator,$faq['id'],(int)$updated['revision']);
    $check($support->manageFaq($operator)===[],'operator FAQ deletion persisted');
    $check(str_contains(ForumSupportAssistant::safeQuestion('password=secret123 sample@mail.invalid sk-abcdefghij'), '[secret]'), 'credentials redacted before AI retrieval');
    $callsBefore=$client->calls;
    $ticket=$support->create($actor,['subject'=>'Как хранить ключ?','message'=>'Криптографический ключ хранится отдельно от пользовательского сообщения.','ai_help'=>true]);
    $ticketId=$ticket['ticket']['id'];
    $check($client->calls===$callsBefore+1,'real ticket creation invokes existing OpenAI source-grounded search once');
    $check($ticket['ticket']['status']==='waiting' && count($ticket['messages'])===2 && $ticket['messages'][1]['role']==='assistant','AI source-backed answer persisted after guarded SQL revision claim');
    $check(count($ticket['messages'][1]['sources'])>0,'AI answer contains live public post sources');
    $check(count($support->mine($actor))===1 && $support->mine($other)===[],'member ticket list is owner-scoped');
    $reject(fn()=> $support->show($other,$ticketId),404,'another member cannot read support transcript');
    $reject(fn()=> $support->handoff($other,$ticketId,['expected_revision'=>2]),404,'another member cannot request handoff');
    $reject(fn()=> $support->queue($other),403,'operator queue requires ACP member permission');
    $check(count($support->queue($operator,'waiting'))===1,'AI answered tickets visible in operator queue');
    $ticket=$support->handoff($actor,$ticketId,['expected_revision'=>(int)$ticket['ticket']['revision']]);
    $check($ticket['ticket']['status']==='open' && (int)$ticket['ticket']['needs_human']===1,'human handoff updates durable queue');
    $reject(fn()=> $support->handoff($actor,$ticketId,['expected_revision'=>2]),409,'stale member revision cannot clobber handoff');
    $ticket=$support->action($operator,$ticketId,['action'=>'claim','expected_revision'=>(int)$ticket['ticket']['revision']]);
    $check($ticket['ticket']['assigned_to']===$operatorId,'operator claims actual SQL ticket');
    $ticket=$support->reply($operator,$ticketId,['message'=>'Пожалуйста, проверьте настройки.','expected_revision'=>(int)$ticket['ticket']['revision']]);
    $check($ticket['ticket']['status']==='waiting' && $ticket['messages'][count($ticket['messages'])-1]['role']==='operator','operator reply persisted in member transcript');
    $ticket=$support->reply($actor,$ticketId,['message'=>'Спасибо, уточните детали.','expected_revision'=>(int)$ticket['ticket']['revision']]);
    $check($ticket['ticket']['status']==='open' && $ticket['messages'][count($ticket['messages'])-1]['role']==='user','member reply reopens human queue');
    $ticket=$support->action($operator,$ticketId,['action'=>'resolve','expected_revision'=>(int)$ticket['ticket']['revision']]);
    $check($ticket['ticket']['status']==='resolved','operator resolves ticket');
    $reject(fn()=> $support->reply($actor,$ticketId,['message'=>'Дополнительный ответ','expected_revision'=>(int)$ticket['ticket']['revision']]),409,'resolved ticket rejects late replies');
    $new=$support->create($actor,['subject'=>'Не работает вход','message'=>'Ошибка при авторизации','ai_help'=>false]);
    $check($new['ticket']['status']==='open' && count($new['messages'])===1,'ticket without AI consent goes straight to human');
    // A later edit revokes old AI citations on the next ticket read, without revealing stale content.
    $pdo->prepare('UPDATE ce_forum_posts SET body=? WHERE id=?')->execute(['Содержимое источника полностью изменилось.',$post]);
    $redacted=$support->show($actor,$ticketId);
    $check($redacted['messages'][1]['sources']===[] && str_contains($redacted['messages'][1]['body'],'скрыт'),'changed source revokes historical AI answer');
    $pdo->prepare('UPDATE ce_forum_posts SET body=? WHERE id=?')->execute(['Криптографический ключ хранится отдельно от пользовательского сообщения.',$post]);
    foreach (range(1,3) as $n)$support->create($actor,['subject'=>'Тестовый вопрос '.$n,'message'=>'Проблема проверочного обращения '.$n]);
    $reject(fn()=> $support->create($actor,['subject'=>'Шестой вопрос','message'=>'Лимит пяти обращений']),429,'SQL-backed 5 tickets per 24 hours quota');
    $pdo->prepare('DELETE FROM ce_users WHERE id IN (?,?)')->execute([$operatorId,$otherId]);
    $calls=$client->calls;
    $pdo->prepare("UPDATE ce_forum_boards SET visibility='private' WHERE id=?")->execute([$board]);
    $private=$search->retrieve($actor,'Криптографический ключ');
    $check($private['sources']===[],'private board blocked even for authenticated member with read ACL');
    $missing=$search->answer($actor,'Криптографический ключ',null,$private);
    $check($missing['no_evidence']===true&&$client->calls===$calls,'no external request when public evidence unavailable');
    $pdo->prepare("UPDATE ce_forum_boards SET visibility='public' WHERE id=?")->execute([$board]);
    $pdo->prepare('UPDATE ce_forum_threads SET deleted_at=? WHERE id=?')->execute([time(),$thread]);
    $check($search->retrieve($actor,'Криптографический ключ')['sources']===[],'removed topic excluded immediately');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()===0,'cost reservations settled after answer');
    echo "Forum AI search + support 1.3.5 real PDO $driver: $checks PASS\n";
}finally{
    $pdo->prepare('DELETE FROM ce_forum_threads WHERE id=?')->execute([$thread]);
    $pdo->prepare('DELETE FROM ce_users WHERE id=?')->execute([$uid]);
    if($before===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$before);
    foreach(glob($tmp.'/storage/app/ai/*')?:[] as $file)@unlink($file);
    @rmdir($tmp.'/storage/app/ai');@rmdir($tmp.'/storage/app');@rmdir($tmp.'/storage');@rmdir($tmp);
}
