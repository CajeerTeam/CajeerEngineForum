#!/usr/bin/env php
<?php
declare(strict_types=1);
// Minimal environment fallback for ASCII-only regression; deployment requires mbstring.
if(!function_exists('mb_strlen')) {function mb_strlen(string $s):int{return strlen($s);}}
if(!function_exists('mb_strtolower')) {function mb_strtolower(string $s):string{return strtolower($s);}}
// Pure validation / citation integrity checks run even in minimal PHP images.
spl_autoload_register(static function(string $class):void {
    $prefix='CajeerEngine\\';
    if(!str_starts_with($class,$prefix))return;
    $path=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require $path;
});
use CajeerEngine\Forum\Service\ForumAiSearch;
use CajeerEngine\Forum\ForumException;

$count=0;
$check=static function(bool $valid,string $msg)use(&$count):void{if(!$valid)throw new RuntimeException('FAIL '.$msg);$count++;};
$valid=ForumAiSearch::question("  How   configure   PHP  ");
$check($valid==='How configure PHP','trimming spaces');
$terms=ForumAiSearch::terms('How configure PHP и сервер');
$check($terms!==[] && count($terms)<=4 && in_array('configure',$terms,true) && in_array('php',$terms,true),'natural question terms retained within bounds');
$check(ForumAiSearch::terms('how what and')===[],'stop words ignored');
$check(count(ForumAiSearch::terms('one two three four five six seven eight nine ten'))<=4,'search query count bounded');
$check(ForumAiSearch::terms('server server server')===['server'],'duplicate tokens excluded');
$sources=[['number'=>1],['number'=>2]];
$check(ForumAiSearch::citations('Ответ [1]',$sources)['valid'],'existing citation accepted');
$check(ForumAiSearch::citations('Ответ [1][2]',$sources)['cited_numbers']===[1,2],'multiple citations');
$check(ForumAiSearch::citations('Только слова',$sources)['valid']===false,'missing citation flagged');
$check(ForumAiSearch::citations('[99]',$sources)['invalid_numbers']===[99],'unknown citation flagged');
$check(ForumAiSearch::citations('[1] [99]',$sources)['valid']===false,'partially invented citations flagged');
$check(ForumAiSearch::citations('[1] [1]',$sources)['cited_numbers']===[1],'deduplicate citations');
foreach([null,0,[], '', 'ab',str_repeat('a',601),"\xff"] as $invalid){
    try{ForumAiSearch::question($invalid);}catch(ForumException $e){$check($e->status===422,'bad query rejected');continue;}
    throw new RuntimeException('Unexpected accepted query');
}
echo "Forum AI search pure PHP: $count PASS\n";
