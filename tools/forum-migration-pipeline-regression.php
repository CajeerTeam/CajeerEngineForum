<?php
/** Pure PHP executable checks for stable phase numbering even without a database driver. */
declare(strict_types=1);
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,EnrichedForumSource,ForumSource};
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,"CajeerEngine\\")){
        $path=$root.'/core/'.str_replace("\\","/",substr($class,13)).'.php';
        if(is_file($path))require $path;
    }
});
final class SampleExtension implements EnrichedForumSource
{
    public function platform():string{return 'xenforo';}
    public function identity():string{return str_repeat('a',64);}
    public function version():string{return '2.3.0';}
    public function plan():array{
        $out=[];foreach(ForumSource::PHASES as $phase)$out[$phase]=['count'=>1,'high'=>10];return $out;
    }
    public function additionalPlan():array{return ['profiles'=>['count'=>2,'high'=>20],'reactions'=>['count'=>3,'high'=>30]];}
    public function additionalBatch(string $phase,int $after,int $high,int $limit):array{return [];}
    public function batch(string $phase,int $cursor,int $high,int $limit):array{return [];}
    public function memberships(int $user):array{return [];}
    public function options(int $topic):array{return [];}
    public function watchers(string $kind,int $id,int $after,int $limit):array{return [];}
    public function ballot(int $topic,int $user):array{return [];}
    public function unsupported():array{return [];}
}
$database=(new ReflectionClass(DatabaseManager::class))->newInstanceWithoutConstructor();
$engine=new ForumMigrationEngine($database,new SampleExtension());
$old=$engine->snapshotPlan(false);$legacyV2=$engine->snapshotPlan(true,2);$new=$engine->snapshotPlan();
$method=(new ReflectionClass(ForumMigrationEngine::class))->getMethod('phaseNames');
$assert=static function(bool $passed,string $label):void{if(!$passed)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";};
$assert(!isset($old['pipeline_version'])&&count($method->invoke($engine,$old))===11,'existing job remains exactly 11 phases');
$assert(($legacyV2['pipeline_version']??null)===2&&count($method->invoke($engine,$legacyV2))===13,'1.1.1/1.1.2 jobs preserve thirteen original phases');
$assert(($new['pipeline_version']??null)===3&&count($method->invoke($engine,$new))===14,'new XenForo jobs add final content links phase');
$assert($new['content_links']===$new['posts'],'post conversion bounds are frozen with source post bounds');
$assert(array_slice($method->invoke($engine,$new),-3)===['profiles','reactions','content_links'],'new conversion follows all original imported entities');
$assert($engine->matchesRecordedPlan($old)&&$engine->matchesRecordedPlan($legacyV2)&&$engine->matchesRecordedPlan($new),'all three ledger formats validate on resume');
$wrong=$new;$wrong['reactions']['high']=31;
$assert(!$engine->matchesRecordedPlan($wrong),'changed source bounds block resume');
$wrong=$new;$wrong['pipeline_version']=5;
try{$method->invoke($engine,$wrong);throw new LogicException('Incompatible source accepted');}catch(RuntimeException $e){
    $assert(str_contains($e->getMessage(),'Community phases require a XenForo source'),'known v5 rejects a source without community/media capabilities');
}
$wrong=$new;$wrong['pipeline_version']=999;
try{$method->invoke($engine,$wrong);throw new LogicException('Unknown version accepted');}catch(RuntimeException $e){
    $assert(str_contains($e->getMessage(),'Unsupported import pipeline'),'unsupported pipeline fails closed');
}
