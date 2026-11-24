<?php
/** Invoke the real MigrationRunner and MySQL migration lock against a deterministic PDO DDL fixture. */
declare(strict_types=1);

use CajeerEngine\Database\MigrationRunner;

spl_autoload_register(static function (string $class): void {
    $prefix='CajeerEngine\\';
    if (!str_starts_with($class,$prefix)) return;
    $path=dirname(__DIR__).'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($path)) require $path;
});

final class RepairStatement extends PDOStatement {
    private array $params=[];
    public function __construct(public string $sql, private RepairDatabase $db) {}
    public function execute(?array $params=null): bool { $this->params=$params??[]; return true; }
    public function fetchColumn(int $column=0): mixed {
        if (str_contains($this->sql,'GET_LOCK') || str_contains($this->sql,'RELEASE_LOCK')) return 1;
        if (str_contains($this->sql,'information_schema.columns')) return 1;
        if (str_contains($this->sql,'DATABASE()')) return 'cajeer_tests';
        if (str_contains($this->sql,'MAX(batch)')) return 1;
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args):array {
        if (str_contains($this->sql,'FROM ce_migrations')) return $this->db->migrations;
        return [];
    }
    public function rowCount():int {return 0;}
}
final class RepairDatabase extends PDO {
    public bool $deleted=false;
    public array $migrations=[];
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function getAttribute(int $attribute):mixed { return $attribute===PDO::ATTR_DRIVER_NAME?'mysql':null; }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs):PDOStatement|false {return new RepairStatement($query,$this);}
    public function prepare(string $query,array $options=[]):PDOStatement|false { return new RepairStatement($query,$this); }
    public function exec(string $statement):int|false {
        if (str_contains($statement,'DELETE FROM ce_migrations')) $this->deleted=true;
        return 0;
    }
}
$dir=sys_get_temp_dir().'/forum-mysql-ddl-'.bin2hex(random_bytes(6));
mkdir($dir,0700);mkdir($dir.'/mysql');
$file=$dir.'/mysql/0100_ddl.sql';file_put_contents($file,'CREATE TABLE example (id INTEGER);');
$checksum=hash_file('sha256',$file);
$pdo=new RepairDatabase();
$pdo->migrations=[['migration'=>basename($file),'batch'=>1,'checksum'=>$checksum,'execution_ms'=>0,'status'=>'failed','error'=>'DDL interrupted','applied_at'=>'2026-11-22 00:00:00']];
$n=0;
$check=static function(bool $ok,string $description)use(&$n):void{
    if (!$ok)throw new RuntimeException('FAIL '.$description);
    $n++;echo 'PASS '.$description."\n";
};
try {
    $runner=new MigrationRunner($pdo,$dir);
    $status=$runner->status('mysql');
    $check($runner->verifyApplied('mysql')['errors'] === [basename($file) => 'failed'], 'verification rejects failed migration');
    $check(count($status['failed'])===1,'failed MySQL DDL is visible in migration status');
    try {$runner->repairFailed('mysql',true);$rejected=false;}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'backup');}
    $check($rejected && !$pdo->deleted,'repair --run cannot erase a partially committed MySQL migration');
    try {$runner->run('mysql');$rejected=false;}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'backup');}
    $check($rejected,'normal migrate also refuses to rerun interrupted MySQL DDL');
    file_put_contents($file,'CREATE TABLE example (id INTEGER, privileged INTEGER);');
    $status=$runner->status('mysql');
    $check(count($status['integrity_errors'])===1 && $status['integrity_errors'][0]['reason']==='failed_checksum_mismatch',
        'failed migration checksum drift is surfaced before repair');
    try {$runner->repairFailed('mysql',true);$rejected=false;}catch(RuntimeException){$rejected=true;}
    $check($rejected && !$pdo->deleted,'modified failed migration cannot be reset or executed');
    // The verifier must be read-only and reject blank/changed checksums,
    // pending migrations, and ledger entries with no corresponding SQL.
    file_put_contents($file,'CREATE TABLE example (id INTEGER);');
    $pdo->migrations[0]['status']='applied';
    $check($runner->verifyApplied('mysql')['ok'], 'valid migration ledger accepted');
    $pdo->migrations[0]['checksum']='';
    $check($runner->verifyApplied('mysql')['errors'] === [basename($file) => 'checksum'], 'blank checksum rejected');
    $pdo->migrations[0]['checksum']=str_repeat('0',64);
    $check($runner->verifyApplied('mysql')['errors'] === [basename($file) => 'checksum'], 'changed checksum rejected');
    $saved=$pdo->migrations;
    $pdo->migrations=[['migration'=>'9999_orphan.sql','status'=>'applied','checksum'=>$checksum]];
    $check($runner->verifyApplied('mysql')['errors'] === [basename($file) => 'pending', '9999_orphan.sql' => 'missing'], 'pending and orphaned ledger entries rejected');
    $pdo->migrations=$saved;
    $pdo->migrations[0]['status']='failed';
    $pdo->migrations[0]['checksum']=$checksum;
    unlink($file);
    $missing=$runner->status('mysql');
    $check(count($missing['integrity_errors'])===1 && $missing['integrity_errors'][0]['reason']==='file_missing',
        'missing SQL file of interrupted migration is detected');
    try {$runner->run('mysql');$rejected=false;}catch(RuntimeException){$rejected=true;}
    $check($rejected, 'missing migration file blocks future schema changes');
    echo "Forum 1.0.9 migration safety: {$n} PASS\n";
} finally {if(is_file($file))unlink($file);rmdir($dir.'/mysql');rmdir($dir);}
