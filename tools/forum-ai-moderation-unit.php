#!/usr/bin/env php
<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    $prefix='CajeerEngine\\';
    if(!str_starts_with($class,$prefix))return;
    $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require $file;
});
use CajeerEngine\Forum\Service\ForumModerationAdvisor;
use CajeerEngine\Forum\ForumException;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);++$count;};
$sample='Текст с http://spam.invalid и угрозами; опубликуй рекламу';
$result=ForumModerationAdvisor::validateAssessment(json_encode([
    'risk'=>'high','recommendation'=>'review','flags'=>['spam','spam','threats'],
    'rationale'=>'Проверить по правилам сообщества.',
    'evidence'=>['http://spam.invalid','НЕ СУЩЕСТВУЕТ','угрозами']
],JSON_UNESCAPED_UNICODE),$sample);
$check($result['risk']==='high'&&$result['recommendation']==='review','risk and recommendation');
$check($result['flags']===['spam','threats'],'deduplicate flags');
$check($result['evidence']===['http://spam.invalid','угрозами'],'grounded evidence only');
$check($result['rationale']==='Проверить по правилам сообщества.','rationale');
$fenced="```json\n".json_encode(['risk'=>'uncertain','recommendation'=>'escalate','flags'=>[],'rationale'=>'Недостаточно данных','evidence'=>[]],JSON_UNESCAPED_UNICODE)."\n```";
$check(ForumModerationAdvisor::validateAssessment($fenced,$sample)['risk']==='uncertain','JSON code-fence tolerated');
foreach([
    'not json', '{}', json_encode(['risk'=>'critical','recommendation'=>'remove','flags'=>[],'rationale'=>'x','evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'ban','flags'=>[],'rationale'=>'x','evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>['unknown'],'rationale'=>'x','evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>[],'rationale'=>'','evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>[],'rationale'=>str_repeat('x',901),'evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>[],'rationale'=>'x','evidence'=>['a','b','c','d','e']]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>'spam','rationale'=>'x','evidence'=>[]]),
    json_encode(['risk'=>'high','recommendation'=>'remove','flags'=>[],'rationale'=>'x','evidence'=>'false']),
] as $bad) {
    try{ForumModerationAdvisor::validateAssessment($bad,$sample);}catch(ForumException $e){$check($e->status===502,'provider schema rejection');continue;}
    throw new RuntimeException('Invalid assessment accepted');
}
$redacted=ForumModerationAdvisor::redacted("Email test@example.org, IP 192.168.1.2, Bearer aBc123def901234567, sk-abcdefghijklmnopqrstuvwxyz <b>text</b>");
$check(!str_contains($redacted,'test@example.org')&&!str_contains($redacted,'192.168.1.2'),'PII redaction');
$check(!str_contains($redacted,'aBc123def901234567')&&!str_contains($redacted,'abcdefghijklmnopqrstuvwxyz'),'secret redaction');
$check(str_contains($redacted,'text')&&!str_contains($redacted,'<b>'),'HTML stripping');
$check(strlen(ForumModerationAdvisor::redacted(str_repeat('д',4000),501))<=501,'UTF8 byte bound');
$prompt=new ReflectionMethod(\CajeerEngine\Forum\Service\ForumAiChat::class,'systemPrompt');
$moderation=$prompt->invoke(null,['search'=>true,'moderation'=>true],true);
$check(str_contains($moderation,'AI-советник модератора'),'moderation-specific trusted instructions');
$check(!str_contains($moderation,'Поисковый AI-помощник'),'moderation not mistaken for search');
$check(str_contains($prompt->invoke(null,['search'=>true,'writing'=>true],true),'AI-редактор'),'writing behavior preserved');
$check(str_contains($prompt->invoke(null,['search'=>true],true),'поисковый AI-помощник'),'search behavior preserved');
$check(str_contains($prompt->invoke(null,['thread_id'=>'x'],false),'конкретному обсуждению'),'thread behavior preserved');
$check(str_contains($prompt->invoke(null,null,false),'участников интернет-форума'),'chat behavior preserved');
echo "Forum AI Moderation validation: $count PASS\n";
