#!/usr/bin/env php
<?php
/** Genuine migration, PDO mutation, ACL and audit; outbound OpenAI HTTP alone is stubbed. */
declare(strict_types=1);

use CajeerEngine\AI\{AiSettings,OpenAiClient};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumTransaction,ForumSupportAssistant,ForumAiSearch,ForumAdminAiActions};
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);require_once $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('Real PDO driver required: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_analytics');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    foreach(glob($root.'/migrations/sqlite/*.sql') as $file)$pdo->exec((string)file_get_contents($file));
}else{
    $pdo=$db->connection();$actual=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($actual!=='forum_analytics')throw new RuntimeException('Unsafe DB target '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
final class AdminActionStubProvider extends OpenAiClient {
    public int $calls=0;public array $input=[];public string $text='';
    public function complete(#[\SensitiveParameter] string $key,string $model,array $input,int $maxOutput,?callable $delta=null):array {
        if($key!=='sk-forum-admin-actions-acceptance-key')throw new RuntimeException('Provider key mismatch');
        $this->calls++;$this->input=$input;
        return ['text'=>$this->text,'usage'=>['input_tokens'=>60,'cached_input_tokens'=>0,'output_tokens'=>35],'response_id'=>'resp_admin_stub'];
    }
}
$admin='61d4109b-49cd-4dda-9658-0311bd0186f2';$outsider='ff7a58ee-4c58-4872-aae8-6bd59488eaca';
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAILED '.$label);$checks++;};
$reject=static function(callable $fn,int $status,string $label)use($check):void {
    try{$fn();}catch(ForumException $e){$check($e->status===$status,$label.' status');return;}
    throw new RuntimeException('Unsafe action accepted: '.$label);
};
$tmp=sys_get_temp_dir().'/forum-admin-actions-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
$previous=getenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY=sk-forum-admin-actions-acceptance-key');
try{
    foreach([$admin,$outsider] as $uid)$pdo->prepare('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)')->execute([$uid,$uid.'@example.invalid','admin-actions','!']);
    foreach(['forum-acp-settings','forum-acp-members'] as $handle){
        $q=$pdo->prepare('SELECT id FROM ce_roles WHERE handle=?');$q->execute([$handle]);$role=$q->fetchColumn();
        if(!is_string($role)||$role==='')throw new RuntimeException('Missing test role '.$handle);
        $pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)')->execute([$admin,$role]);
    }
    $provider=new AdminActionStubProvider();$ai=new AiSettings($tmp);$chat=new ForumAiChat($db,$ai,$provider);
    $settings=$chat->administrator();$chat->configure(['enabled'=>true,'model'=>'gpt-4.1-mini','max_output_tokens'=>384,
        'daily_request_limit'=>80,'monthly_token_limit'=>150000,'revision'=>$settings['revision']]);
    $tx=new ForumTransaction($db,new AuditLogRepository($db));
    $svc=new ForumAdminAiActions($db,$tx,$chat,new ForumSupportAssistant($db,new ForumAiSearch($db,$tx,$chat)));
    $actor=new ForumAccessPolicy($admin,['forum:acp:settings','forum:acp:members']);
    $guest=new ForumAccessPolicy($outsider,['forum:acp:settings','forum:acp:members']);
    $source=$tx->settings()->snapshot();$old=$source['values']['name'];
    $reject(fn()=> $svc->list($guest),403,'live ACL denied');
    $reject(fn()=> $svc->propose($actor,'policy_name','Обновить название',null,false),422,'external consent mandatory');
    $check($provider->calls===0,'no OpenAI before consent');
    $provider->text=json_encode(['value'=>'Тестовый форум сообщества','reason'=>'Более ясное имя'],JSON_UNESCAPED_UNICODE);
    $proposal=$svc->propose($actor,'policy_name','Измените название',null,true);
    $check($provider->calls===1&&$proposal['state']==='pending','generation prepares only');
    $check($tx->settings()->snapshot()['values']['name']===$old,'no write before approval');
    $check(str_contains($provider->input[0]['content'],'подготовки изменений'),'safe system role');
    $check(!str_contains($provider->input[0]['content'],$admin),'no actor ID in provider prompt');
    $reject(fn()=> $svc->get($guest,$proposal['id']),403,'other user denied by live ACL');
    $reject(fn()=> $svc->decide($actor,$proposal['id'],str_repeat('0',64),'approve'),409,'digest mismatch');
    $applied=$svc->decide($actor,$proposal['id'],$proposal['digest'],'approve');
    $check($applied['state']==='applied'&&$tx->settings()->snapshot()['values']['name']==='Тестовый форум сообщества','actual policy mutation');
    $reject(fn()=> $svc->decide($actor,$proposal['id'],$proposal['digest'],'approve'),409,'replay impossible');
    $provider->text=json_encode(['value'=>'Изменение отклонено','reason'=>'Проверка отклонения'],JSON_UNESCAPED_UNICODE);
    $rejected=$svc->propose($actor,'policy_name','Измените название',null,true);
    $done=$svc->decide($actor,$rejected['id'],$rejected['digest'],'reject');
    $check($done['state']==='rejected'&&$tx->settings()->snapshot()['values']['name']==='Тестовый форум сообщества','reject never mutates');
    $provider->text=json_encode(['value'=>'Устаревшее изменение','reason'=>'Конфликт ревизии'],JSON_UNESCAPED_UNICODE);
    $stale=$svc->propose($actor,'policy_name','Измените название',null,true);
    $policy=$tx->settings()->snapshot();$values=$policy['values'];unset($values['registration_open'],$values['paused']);
    $values['name']='Изменено вручную';$tx->settings()->save($actor,['revision'=>$policy['revision'],'values'=>$values]);
    $reject(fn()=> $svc->decide($actor,$stale['id'],$stale['digest'],'approve'),409,'policy revision conflict');
    $check($tx->settings()->snapshot()['values']['name']==='Изменено вручную','stale AI cannot overwrite admin');
    $provider->text=json_encode(['question'=>'Как начать обсуждение?','answer'=>'Откройте раздел и создайте новую тему.','reason'=>'Частый вопрос'],JSON_UNESCAPED_UNICODE);
    $faq=$svc->propose($actor,'faq_create','Создайте FAQ',null,true);
    $applied=$svc->decide($actor,$faq['id'],$faq['digest'],'approve');
    $check($applied['state']==='applied' && $applied['result']['published']===false,'FAQ draft created unpublished');
    $faqId=$applied['result']['faq_id'];
    $q=$pdo->prepare('SELECT published FROM ce_forum_support_faq WHERE id=?');$q->execute([$faqId]);
    $check((int)$q->fetchColumn()===0,'real FAQ row persisted private');
    $reject(fn()=> $svc->propose($actor,'faq_update','Перепишите FAQ',$faqId,true),404,'unpublished FAQ not sent to model');
    $q=$pdo->prepare('UPDATE ce_forum_support_faq SET published=1, revision=revision+1 WHERE id=?');$q->execute([$faqId]);
    $provider->text=json_encode(['question'=>'Как начать обсуждение?','answer'=>'Выберите публичный раздел и нажмите «Новая тема».','reason'=>'Точность'],JSON_UNESCAPED_UNICODE);
    $suggested=$svc->propose($actor,'faq_update','Уточните FAQ',$faqId,true);
    $check($suggested['state']==='pending','published FAQ edit proposed');
    $pdo->prepare('DELETE FROM ce_user_roles WHERE user_id=?')->execute([$admin]);
    $reject(fn()=> $svc->decide($actor,$suggested['id'],$suggested['digest'],'approve'),403,'live permission revocation');
    $pdo->prepare('INSERT INTO ce_user_roles(user_id,role_id) SELECT ?,id FROM ce_roles WHERE handle IN (?,?)')
        ->execute([$admin,'forum-acp-settings','forum-acp-members']);
    $updated=$svc->decide($actor,$suggested['id'],$suggested['digest'],'approve');
    $check($updated['state']==='applied','permitted FAQ update applies');
    $q=$pdo->prepare('SELECT answer,published,revision FROM ce_forum_support_faq WHERE id=?');$q->execute([$faqId]);$final=$q->fetch(PDO::FETCH_ASSOC);
    $check(str_contains($final['answer'],'Новая тема')&&(int)$final['published']===1&&(int)$final['revision']>=3,'published flag preserved and FAQ revision incremented');
    $audit=$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event IN ('forum.ai.admin.proposed','forum.ai.admin.approve','forum.ai.admin.reject')")->fetchColumn();
    $check((int)$audit>=7,'atomic audit trail present');
    $check(count($svc->list($actor))>=5,'durable actor-bound proposal history');
    echo "Forum controlled admin AI real PDO $driver: $checks PASS\n";
}finally{
    $pdo->prepare('DELETE FROM ce_users WHERE id IN (?,?)')->execute([$admin,$outsider]);
    if($previous===false)putenv('OPENAI_API_KEY');else putenv('OPENAI_API_KEY='.$previous);
    foreach(glob($tmp.'/storage/app/ai/*')?:[] as $item)@unlink($item);
    @rmdir($tmp.'/storage/app/ai');@rmdir($tmp.'/storage/app');@rmdir($tmp.'/storage');@rmdir($tmp);
}
