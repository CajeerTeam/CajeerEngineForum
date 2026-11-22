<?php
/** Driver-free v4 avatar path and legacy pipeline checks. */
declare(strict_types=1);
use CajeerEngine\Forum\Migration\XenForoAvatar;
$root=dirname(__DIR__);
require $root.'/core/Forum/Migration/XenForoAvatar.php';
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php';
        if(is_file($file))require $file;
    }
});
$checks=0;
$verify=static function(bool $ok,string $label)use(&$checks):void {
    if(!$ok)throw new RuntimeException($label);
    $checks++;echo 'PASS '.$label."\n";
};
$verify(XenForoAvatar::paths('/frozen/data/avatars',1)===['/frozen/data/avatars/o/0/1.jpg','/frozen/data/avatars/l/0/1.jpg'],'original and large avatar fallback');
$verify(XenForoAvatar::paths('/frozen/data/avatars',12001)[0]==='/frozen/data/avatars/o/12/12001.jpg','user 12001 shard');
foreach([0,-2] as $value){
    try{XenForoAvatar::paths('/any',$value);throw new LogicException('Invalid source id accepted');}
    catch(InvalidArgumentException){$verify(true,'invalid avatar user id rejected');}
}
$sourceReflection=new ReflectionClass(\CajeerEngine\Forum\Migration\MultiForumSource::class);
$source=$sourceReflection->newInstanceWithoutConstructor();
$sourceReflection->getProperty('type')->setValue($source,'xenforo');
$db=(new ReflectionClass(\CajeerEngine\Database\DatabaseManager::class))->newInstanceWithoutConstructor();
$engine=new \CajeerEngine\Forum\Migration\ForumMigrationEngine($db,$source);
$phaseNames=(new ReflectionClass($engine))->getMethod('phaseNames');
$base=['posts'=>['count'=>4,'high'=>33],'content_links'=>['count'=>4,'high'=>33]];
$v4=$base+['pipeline_version'=>4,'avatars'=>['count'=>2,'high'=>3],'follows'=>['count'=>3,'high'=>3]];
$phases=$phaseNames->invoke($engine,$v4);
$verify(count($phases)===16&&array_slice($phases,-3)===['avatars','follows','content_links'],'v4 live phase order is append-only');
$verify(count($phaseNames->invoke($engine,['pipeline_version'=>3]+$base))===14,'v3 old checkpoints preserved');
$verify(count($phaseNames->invoke($engine,['pipeline_version'=>2]+$base))===13,'v2 old checkpoints preserved');
$verify(count($phaseNames->invoke($engine,$base))===11,'v1 old checkpoints preserved');
try{$bad=$v4;unset($bad['follows']);$phaseNames->invoke($engine,$bad);throw new LogicException('incomplete plan accepted');}
catch(RuntimeException){$verify(true,'incomplete v4 community ledger rejected');}
echo "XenForo avatar source path unit: $checks PASS\n";
