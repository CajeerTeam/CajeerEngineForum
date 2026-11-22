<?php
/** Verify immutable v6/v7 phase semantics without PDO; v7 end-to-end is tested separately. */
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $name=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($name))require $name;
    }
});
$assert=static function(bool $v,string $reason):void{if(!$v)throw new RuntimeException('FAIL '.$reason);echo 'PASS '.$reason."\n";};
$r=new ReflectionClass(CajeerEngine\Forum\Migration\ForumMigrationEngine::class);
$engine=$r->newInstanceWithoutConstructor();
$source=(new ReflectionClass(CajeerEngine\Forum\Migration\InvisionSource::class))->newInstanceWithoutConstructor();
$r->getProperty('source')->setValue($engine,$source);
$phases=$r->getMethod('phaseNames');
$old=['pipeline_version'=>6];foreach(CajeerEngine\Forum\Migration\InvisionSource::CORE_PHASES as $kind)$old[$kind]=['count'=>0,'high'=>0];
$assert($phases->invoke($engine,$old)===CajeerEngine\Forum\Migration\InvisionSource::CORE_PHASES,'v6 six-phase ledger unchanged');
$new=$old;$new['pipeline_version']=7;
foreach(CajeerEngine\Forum\Migration\InvisionSource::CONTENT_PHASES as $kind)$new[$kind]=['count'=>0,'high'=>0];
$new['invision_content']=$new['posts'];
$assert($phases->invoke($engine,$new)===array_merge(CajeerEngine\Forum\Migration\InvisionSource::CORE_PHASES,CajeerEngine\Forum\Migration\InvisionSource::CONTENT_PHASES),'v7 appends content, no old checkpoint shift');
$changed=$new;$changed['invision_content']['count']=1;
try{$phases->invoke($engine,$changed);throw new LogicException('expected rejection');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'bounds mismatch'),'tampered source content bounds refused');}
$missing=$new;unset($missing['attachments']);
try{$phases->invoke($engine,$missing);throw new LogicException('expected rejection');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'content ledger'),'missing media phase blocked');}
$v8=$new;$v8['pipeline_version']=8;
foreach(CajeerEngine\Forum\Migration\InvisionSource::COMMUNITY_PHASES as $kind)$v8[$kind]=['count'=>0,'high'=>0];
$v8['inv_profiles']=$v8['users'];$v8['inv_pm_content']=$v8['inv_messages'];
$v8['source_community_schema_sha256']=str_repeat('a',64);
$assert($phases->invoke($engine,$v8)===array_merge(
    CajeerEngine\Forum\Migration\InvisionSource::CORE_PHASES,
    CajeerEngine\Forum\Migration\InvisionSource::CONTENT_PHASES,
    CajeerEngine\Forum\Migration\InvisionSource::COMMUNITY_PHASES),
    'v8 appends six community phases without altering earlier checkpoints');
$invalid=$v8;$invalid['inv_pm_content']['count']=1;
try{$phases->invoke($engine,$invalid);throw new LogicException('expected rejection');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'community ledger'),'tampered private-content bounds refused');}
$invalid=$v8;unset($invalid['inv_follow']);
try{$phases->invoke($engine,$invalid);throw new LogicException('expected rejection');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'community ledger'),'missing follow phase blocked');}
$unknown=$new;$unknown['pipeline_version']=9;
try{$phases->invoke($engine,$unknown);throw new LogicException('expected rejection');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'Unsupported import pipeline'),'unknown pipeline rejected');}
