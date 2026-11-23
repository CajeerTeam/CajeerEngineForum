#!/usr/bin/env php
<?php
/** No database or provider required; deterministic quality and excerpt regression. */
declare(strict_types=1);
foreach(['mb_strlen'=>'strlen','mb_strtolower'=>'strtolower','mb_stripos'=>'stripos','mb_substr'=>'substr','mb_strcut'=>'substr'] as $mb=>$native){
    if(!function_exists($mb)){
        // ASCII-only fallback for the local no-mbstring harness.
        if($mb==='mb_strcut'){function mb_strcut(string $s,int $start,int $length,string $encoding='UTF-8'):string{return substr($s,$start,$length);}}
        elseif($mb==='mb_substr'){function mb_substr(string $s,int $start,?int $length=null,string $encoding='UTF-8'):string{return substr($s,$start,$length);}}
        elseif($mb==='mb_stripos'){function mb_stripos(string $s,string $needle,int $offset=0,string $encoding='UTF-8'):int|false{return stripos($s,$needle,$offset);}}
    }
}
spl_autoload_register(static function(string $class):void{
    $prefix='CajeerEngine\\';
    if(str_starts_with($class,$prefix)){
        $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
});
use CajeerEngine\Forum\Service\ForumAiSearch;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$candidates=[
 ['thread_id'=>'a','post_id'=>'','score'=>3,'hits'=>1],
 ['thread_id'=>'b','post_id'=>'p2','score'=>12,'hits'=>2],
 ['thread_id'=>'b','post_id'=>'p3','score'=>6,'hits'=>1],
 ['thread_id'=>'b','post_id'=>'p4','score'=>6,'hits'=>1],
 ['thread_id'=>'c','post_id'=>'p5','score'=>6,'hits'=>1],
 ['thread_id'=>'b','post_id'=>'p6','score'=>6,'hits'=>1],
];
$ranked=ForumAiSearch::rankCandidates($candidates);
$check($ranked[0]['post_id']==='p2','best post match beats title-only match');
$check(count(array_unique(array_column(array_slice($ranked,0,3),'thread_id')))==3,'first pass prioritizes diverse threads');
$check(count(array_filter($ranked,static fn($x)=>$x['thread_id']==='b'))===3,'one topic cannot monopolize evidence');
$check(count($ranked)===5,'remaining posts fill bounded space');
$reflect=new ReflectionMethod(ForumAiSearch::class,'excerpt');
$body=str_repeat('ordinary text ',80).'supercluster precise result '.str_repeat('tail ',80);
$excerpt=$reflect->invoke(null,$body,['supercluster']);
$check(str_contains($excerpt,'supercluster'),'source excerpt includes term far from the start');
$check(str_starts_with($excerpt,'…') && strlen($excerpt)<=824,'excerpt stays bounded and indicates omitted prefix');
$check($reflect->invoke(null,'simple <b>markup</b> text',[])==='simple markup text','source markup is stripped');
$check(ForumAiSearch::citations('evidence [1]',[['number'=>1]])['valid'],'existing citation verification unchanged');
echo "Forum knowledge search quality: $checks PASS\n";
