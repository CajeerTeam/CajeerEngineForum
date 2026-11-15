<?php
/** Transaction failure-injection checks; no database driver required. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,DatabaseUnavailable};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){$file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($file))require $file;}
});
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function failure(string $state,int $native=0,string $message='injected'):PDOException{$e=new PDOException($message);$e->errorInfo=[$state,$native,$message];return $e;}
final class FaultPDO extends PDO {
    public bool $active=false;public int $rollbacks=0;public ?PDOException $commitError=null;public ?PDOException $rollbackError=null;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function beginTransaction():bool{$this->active=true;return true;}
    public function commit():bool{if($this->commitError)throw $this->commitError;$this->active=false;return true;}
    public function rollBack():bool{$this->rollbacks++;if($this->rollbackError)throw $this->rollbackError;$this->active=false;return true;}
    public function exec(string $statement):int|false{return 0;}
}
foreach([['08006',0],['57P01',0],['53300',0],['HY000',2006],['HY000',2013]] as [$state,$native])check(DatabaseUnavailable::causedBy(failure($state,$native)),"outage $state/$native");
check(DatabaseUnavailable::causedBy(failure('HY000',7,'server closed the connection unexpectedly')),'libpq disconnect without SQLSTATE');
foreach([['23505',0],['40P01',0],['40001',0],['HY000',5],['HY000',7],['57014',0]] as [$state,$native])check(!DatabaseUnavailable::causedBy(failure($state,$native)),"not outage $state/$native");
// Inject only the transport boundary; exercise production transaction logic.
$db=new DatabaseManager(new ConfigRepository(sys_get_temp_dir().'/ce-reliability-no-storage'));
$property=new ReflectionProperty(DatabaseManager::class,'pdo');
$pdo=new FaultPDO();$property->setValue($db,$pdo);$calls=0;
check($db->transaction(function()use(&$calls){$calls++;return 42;})===42&&$calls===1,'successful transaction commits once');
$error=failure('23505');try{$db->transaction(fn()=>throw $error);throw new LogicException('expected failure');}catch(PDOException $e){check($e===$error&&$pdo->rollbacks===1&&$property->getValue($db)===$pdo,'integrity failure rolls back without disconnect');}
$pdo->commitError=failure('08006');$pdo->rollbackError=failure('HY000',7,'no connection to the server');$calls=0;
try{$db->transaction(function()use(&$calls){$calls++;return 1;});throw new LogicException('expected outage');}catch(DatabaseUnavailable $e){check($e->getPrevious()===$pdo->commitError&&$calls===1&&$property->getValue($db)===null,'ambiguous commit is not replayed; rollback failure preserves original cause');}
$pdo=new FaultPDO();$property->setValue($db,$pdo);$error=failure('23505');$pdo->rollbackError=failure('08006');
try{$db->transaction(fn()=>throw $error);throw new LogicException('expected failure');}catch(PDOException $e){check($e===$error&&$property->getValue($db)===null,'rollback failure discards connection and preserves original exception');}
