<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'CajeerEngine\\')){$file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($file))require $file;}});
use CajeerEngine\Forum\Service\PlatformWorkflows;
use CajeerEngine\Forum\ForumException;
$n=0;$check=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException($label);$n++;};
$base=['id'=>null,'revision'=>0,'name'=>'Routing','trigger_name'=>'support.requested','action_name'=>'support.assign','operator_id'=>'11111111-1111-4111-8111-111111111111','condition_value'=>0,'interval_seconds'=>0,'enabled'=>true];
$check(PlatformWorkflows::validate($base)===$base,'valid event');
$scheduled=array_replace($base,['trigger_name'=>'schedule','interval_seconds'=>60,'condition_value'=>600]);$check(PlatformWorkflows::validate($scheduled)===$scheduled,'valid schedule');
$aggregate=array_replace($scheduled,['action_name'=>'analytics.aggregate','operator_id'=>null,'condition_value'=>10]);$check(PlatformWorkflows::validate($aggregate)===$aggregate,'valid analytics');
foreach([['enabled'=>'true'],['action_name'=>'shell'],['operator_id'=>'bad'],['condition_value'=>1],['interval_seconds'=>60],['trigger_name'=>'webhook'],['name'=>''],['revision'=>1],['id'=>'bad'],['script'=>'phpinfo()'],['action_name'=>'analytics.aggregate','operator_id'=>null],['name'=>str_repeat('a',121)]] as $bad){try{PlatformWorkflows::validate(array_replace($base,$bad));throw new RuntimeException('Unsafe accepted');}catch(ForumException $e){$check($e->status===422,'rejected invalid rule');}}
foreach([['interval_seconds'=>59],['interval_seconds'=>86401],['condition_value'=>604801],['condition_value'=>'5']] as $bad){try{PlatformWorkflows::validate(array_replace($scheduled,$bad));throw new RuntimeException('Unsafe accepted');}catch(ForumException $e){$check($e->status===422,'rejected invalid schedule');}}
echo "Platform workflow validation: $n PASS\n";
