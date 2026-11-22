<?php
/** Pure-PHP boundary tests; actual PDO transactional tests run in the companion suite. */
declare(strict_types=1);
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource,XenForoContent,LegacyForumUrl};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
$n=0;$check=static function(bool $ok,string $message)use(&$n):void {if(!$ok)throw new RuntimeException($message);$n++;echo "PASS $message\n";};
$source=(new ReflectionClass(MultiForumSource::class))->newInstanceWithoutConstructor();
(new ReflectionClass(MultiForumSource::class))->getProperty('type')->setValue($source,'xenforo');
$db=(new ReflectionClass(DatabaseManager::class))->newInstanceWithoutConstructor();
$engine=new ForumMigrationEngine($db,$source);
$names=(new ReflectionClass($engine))->getMethod('phaseNames');
$plan=['pipeline_version'=>5,'posts'=>['count'=>3,'high'=>30],'content_links'=>['count'=>3,'high'=>30],
    'avatars'=>['count'=>0,'high'=>0],'follows'=>['count'=>0,'high'=>0],
    'source_media_schema_sha256'=>str_repeat('a',64),
    'conversations'=>['count'=>1,'high'=>90],'conversation_messages'=>['count'=>2,'high'=>70],
    'conversation_attachments'=>['count'=>1,'high'=>43],'conversation_content'=>['count'=>2,'high'=>70]];
$steps=$names->invoke($engine,$plan);
$check(count($steps)===20&&array_slice($steps,-4)===['conversations','conversation_messages','conversation_attachments','conversation_content'],'v5 appends executable private media phases');
$v4=$plan;$v4['pipeline_version']=4;
$check(count($names->invoke($engine,$v4))===16,'old v4 checkpoints unchanged');
$v3=$plan;$v3['pipeline_version']=3;
$check(count($names->invoke($engine,$v3))===14,'old v3 checkpoints unchanged');
$v2=$plan;$v2['pipeline_version']=2;
$check(count($names->invoke($engine,$v2))===13,'old v2 checkpoints unchanged');
$check(count($names->invoke($engine,[]))===11,'old v1 checkpoints unchanged');
foreach(['conversations','conversation_messages','conversation_attachments','conversation_content','source_media_schema_sha256'] as $missing){
    $bad=$plan;unset($bad[$missing]);
    try{$names->invoke($engine,$bad);throw new LogicException('Missing ledger accepted');}
    catch(RuntimeException){$check(true,'missing '.$missing.' ledger is rejected');}
}
$resolve=static fn(string $kind,int $id):?string=>match($kind){
    'conversations'=>$id===90?'/forum/conversations/11111111-1111-4111-8111-111111111111':null,
    'attachments'=>$id===43?'/api/v1/forum/attachments/22222222-2222-4222-8222-222222222222/download':null,
    'users'=>$id===1?'/forum/members/33333333-3333-4333-8333-333333333333':null,
    default=>null,
};
$body=XenForoContent::convert('[b]Secret[/b] [USER=1]Alice[/USER] [ATTACH]43[/ATTACH] [URL=https://old.example/conversations/private.90/]Open[/URL]',$resolve,'https://old.example');
$check(str_contains($body,'[b]Secret[/b]')&&str_contains($body,'/forum/members/33333333-3333-4333-8333-333333333333'),'private content keeps supported formatting and mapped user');
$check(str_contains($body,'/api/v1/forum/attachments/22222222-2222-4222-8222-222222222222/download'),'private attachment uses permission-checked URL');
$check(str_contains($body,'/forum/conversations/11111111-1111-4111-8111-111111111111'),'private legacy conversation link uses local restricted URL');
$check(!str_contains(XenForoContent::convert('[ATTACH]404[/ATTACH]',$resolve),'download'),'unavailable private attachment not exposed');
$check(LegacyForumUrl::resolve('xenforo','goto/post',['id'=>'30'])===['posts','30'],'legacy XenForo post redirect recognized');
$check(LegacyForumUrl::resolve('xenforo','threads/a.20/post-30',[])===['posts','30'],'legacy inline post redirect recognized');
try{LegacyForumUrl::resolve('xenforo','conversations/private.90',[]);throw new LogicException('Private legacy redirect leaked');}
catch(ForumException $e){$check($e->status===404,'public legacy service rejects private redirects');}
echo "XenForo v5 private media unit: $n PASS\n";
