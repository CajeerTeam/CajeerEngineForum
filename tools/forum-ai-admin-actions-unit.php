#!/usr/bin/env php
<?php

declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    if(!str_starts_with($class,'CajeerEngine\\'))return;
    $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,13)).'.php';
    if(is_file($file))require $file;
});
use CajeerEngine\Forum\Service\{ForumAdminAiActions,ForumAiChat};
use CajeerEngine\Forum\ForumException;
$n=0;$check=function(bool $value,string $label)use(&$n):void{if(!$value)throw new RuntimeException($label);$n++;};
$reject=function(callable $fn,int $status)use($check):void{
    try{$fn();}catch(ForumException $e){$check($e->status===$status,'rejected status '.$status);return;}
    throw new RuntimeException('Unsafe model proposal accepted');
};
$source=['revision'=>2,'before'=>['name'=>'Прежнее имя']];
$good='{"value":"Новое имя","reason":"Сделать название понятным"}';
$r=ForumAdminAiActions::validateProposal('policy_name',$good,$source);
$check($r['payload']['name']==='Новое имя','policy name');
$check($r['reason']==='Сделать название понятным','reason preserved');
$check(ForumAdminAiActions::validateProposal('policy_description','{"value":"Описание форума","reason":"Ясность"}',['before'=>['description'=>'Старое']])['payload']['description']==='Описание форума','description');
$check(ForumAdminAiActions::validateProposal('faq_create','{"question":"Как открыть тему?","answer":"Откройте раздел и нажмите кнопку создания темы.","reason":"FAQ"}',['before'=>[]])['payload']['question']==='Как открыть тему?','FAQ draft');
$faq=['before'=>['question'=>'Как открыть тему?','answer'=>'Нажмите создание темы.']];
$check(ForumAdminAiActions::validateProposal('faq_update','{"question":"Как открыть тему?","answer":"Откройте доступный раздел и выберите создание темы.","reason":"Ясность"}',$faq)['payload']['answer']!==$faq['before']['answer'],'faq update');
foreach ([
    ['policy_name','{"value":"Прежнее имя","reason":"Без изменений"}',$source,422],
    ['policy_name','{"value":"<script>alert(1)</script>","reason":"x"}',$source,422],
    ['policy_name','{"value":"Новое имя","reason":"x","execute":true}',$source,502],
    ['policy_name','{"value":"Новое имя"}',$source,502],
    ['policy_name','not json',$source,502],
    ['policy_name','{"value":123,"reason":"x"}',$source,422],
    ['faq_update','{"question":"Как открыть тему?","answer":"Нажмите создание темы.","reason":"x"}',$faq,422],
    ['faq_create','{"question":"Q?","answer":"x","reason":"x"}',['before'=>[]],422],
    ['role_promote','{"value":"admin","reason":"x"}',$source,422],
] as [$action,$value,$src,$status])$reject(fn()=>ForumAdminAiActions::validateProposal($action,$value,$src),$status);
$prompt=(new ReflectionMethod(ForumAiChat::class,'systemPrompt'))->invoke(null,['search'=>true,'admin_actions'=>true],true);
$check(str_contains($prompt,'никогда')||str_contains($prompt,'Никогда'),'isolated trusted admin role');
$check(str_contains((new ReflectionMethod(ForumAiChat::class,'systemPrompt'))->invoke(null,['search'=>true,'moderation'=>true],true),'модератора'),'moderation unchanged');
echo "Forum admin AI actions: $n PASS\n";
