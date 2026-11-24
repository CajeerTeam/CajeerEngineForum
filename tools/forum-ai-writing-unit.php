#!/usr/bin/env php
<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    $prefix='CajeerEngine\\';
    if(!str_starts_with($class,$prefix))return;
    $file=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require $file;
});
use CajeerEngine\Forum\Service\ForumWritingAssistant;
use CajeerEngine\Forum\ForumException;

$count=0;
$valid=['mode'=>'fix','body'=>'  Ошибка в тексте  ','body_format'=>'plain','title'=>'Пример'];
$result=ForumWritingAssistant::validate($valid);
if($result['body']!=='  Ошибка в тексте  '||$result['title']!=='Пример')throw new RuntimeException('Trim failed');++$count;
foreach(['fix','improve','format','title'] as $mode){$v=$valid;$v['mode']=$mode; if(ForumWritingAssistant::validate($v)['mode']!==$mode)throw new RuntimeException('Mode failed');++$count;}
foreach(['plain','markdown','bbcode'] as $format){$v=$valid;$v['body_format']=$format; if(ForumWritingAssistant::validate($v)['body_format']!==$format)throw new RuntimeException('Format failed');++$count;}
foreach([
    ['body'=>''], ['body'=>str_repeat('a',12001)], ['body'=>"\xff"],
    ['title'=>str_repeat('t',181)], ['title'=>"\xff"],
    ['body_format'=>'html'], ['mode'=>'publish'], ['mode'=>''],
    ['unexpected'=>'secret'], ['body'=>['array']], ['title'=>42],
] as $bad){try{ForumWritingAssistant::validate(array_replace($valid,$bad));}catch(ForumException $e){if($e->status!==422)throw $e;++$count;continue;}throw new RuntimeException('Invalid request accepted: '.json_encode($bad));}
echo "Forum AI writing validation: $count PASS\n";
