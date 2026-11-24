#!/usr/bin/env php
<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    $prefix='CajeerEngine\\';
    if(!str_starts_with($class,$prefix))return;
    $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require $file;
});
use CajeerEngine\Forum\Service\{ForumAiInsights,ForumAiChat};
use CajeerEngine\Forum\ForumException;
$n=0;$check=static function(bool $ok,string $description)use(&$n):void{if(!$ok)throw new RuntimeException($description);++$n;};
$reject=static function(callable $fn,int $status)use($check):void{try{$fn();}catch(ForumException $e){$check($e->status===$status,'error status');return;}throw new RuntimeException('Rejected input accepted');};
$report=['from'=>'2026-11-01','until'=>'2026-11-10','days'=>10,'summary'=>[
    'total'=>33,'page_views'=>25,'actions'=>8,'previous_total'=>16,'previous_page_views'=>12,'previous_actions'=>4],
    'comparison'=>['available'=>true,'from'=>'2026-10-22','until'=>'2026-10-31'],
    'daily'=>array_map(static fn(int $i):array=>['day'=>sprintf('2026-11-%02d',$i+1),'total'=>$i+1,'views'=>$i,'actions'=>1,'events'=>['forum_thread_view'=>$i]],range(0,9)),
    'breakdown'=>[['event_name'=>'forum_thread_view','total'=>25],['event_name'=>'forum_thread_created','total'=>8]]];
$r=ForumAiInsights::facts($report,'views');
$check($r['focus']==='views'&&$r['days']===10,'period and focus');
$check($r['peak_day']==='2026-11-10','peak UTC day');
$ids=array_column($r['facts'],'value','id');
$check($ids['events.views']===25,'authoritative views');
$check($ids['previous.total']===16,'comparison only when available');
$check($ids['focus.recent']===42&&$ids['focus.before']===3,'disjoint recent and earlier days');
$check($ids['event.forum_thread_view']===25,'breakdown');
$r2=ForumAiInsights::facts($report,'event:forum_thread_created');
$check($r2['focus']==='event:forum_thread_created','select existing event');
$reject(fn()=>ForumAiInsights::facts($report,'event:private'),422);
$noPrior=$report;$noPrior['comparison']['available']=false;
$noPrior['summary']['previous_total']=$noPrior['summary']['previous_page_views']=$noPrior['summary']['previous_actions']=null;
$check(!array_key_exists('previous.total',array_column(ForumAiInsights::facts($noPrior,'total')['facts'],'value','id')),'no invented prior window');
$good=json_encode(['headline'=>'Рост интереса к обсуждениям','observations'=>[
    ['kind'=>'observation','text'=>'Суммарная активность выше предыдущего периода.','evidence_ids'=>['events.total','previous.total']],
    ['kind'=>'hypothesis','text'=>'Возможно, повлияли новые обсуждения; причины в данных не указаны.','evidence_ids'=>['events.actions']]]],JSON_UNESCAPED_UNICODE);
$parsed=ForumAiInsights::validate($good,$r['facts']);
$check(count($parsed['observations'])===2,'valid structured report');
$check($parsed['observations'][0]['evidence'][0]['value']===33,'evidence values come from server');
foreach([
    'not json', '{}',
    json_encode(['headline'=>'bad','observations'=>[['kind'=>'observation','text'=>'a','evidence_ids'=>['invented']]]]),
    json_encode(['headline'=>'bad','observations'=>[['kind'=>'observation','text'=>'Статистика выросла на 9999%','evidence_ids'=>['events.total']]]]),
    json_encode(['headline'=>'bad','observations'=>[['kind'=>'action','text'=>'Удалить пользователей','evidence_ids'=>['events.total']]]]),
    json_encode(['headline'=>'<img>','observations'=>[['kind'=>'observation','text'=>'x','evidence_ids'=>['events.total']]]]),
    json_encode(['headline'=>'bad','observations'=>[['kind'=>'observation','text'=>'x','evidence_ids'=>[]]]]),
    json_encode(['headline'=>'bad','observations'=>[]])
] as $bad)$reject(fn()=>ForumAiInsights::validate($bad,$r['facts']),502);
$method=new ReflectionMethod(ForumAiChat::class,'systemPrompt');
$check(str_contains($method->invoke(null,['search'=>true,'insights'=>true],true),'аналитический советник'),'insights system role');
$check(str_contains($method->invoke(null,['search'=>true,'moderation'=>true],true),'AI-советник модератора'),'moderation unchanged');
$check(str_contains($method->invoke(null,['search'=>true,'writing'=>true],true),'AI-редактор'),'writing unchanged');
$check(str_contains($method->invoke(null,['search'=>true],true),'поисковый AI-помощник'),'search unchanged');
echo "Forum AI Insights: $n PASS\n";
