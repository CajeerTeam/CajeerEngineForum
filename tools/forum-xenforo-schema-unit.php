<?php
/** Test the PHP schema probe even on hosts without a PDO SQLite extension. */
declare(strict_types=1);

require dirname(__DIR__).'/core/Forum/Migration/XenForoSchema.php';

use CajeerEngine\Forum\Migration\XenForoSchema;

final class FixtureStatement extends PDOStatement
{
    public function __construct(private array $values) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->values; }
}
final class FixturePdo extends PDO
{
    public function __construct(public array $tables) {}
    public function getAttribute(int $attribute): mixed { return 'sqlite'; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (!preg_match('/\APRAGMA table_info\("(xf_[A-Za-z0-9_]+)"\)\z/D', $query, $match)) {
            throw new RuntimeException('Unexpected schema SQL: '.$query);
        }
        $rows=[];
        foreach ($this->tables[$match[1]]??[] as $column) $rows[]=['name'=>$column];
        return new FixtureStatement($rows);
    }
}

$sql=file_get_contents(__DIR__.'/forum-tests/multi_forum_fixture.sql');
$tables=[];
preg_match_all('/CREATE TABLE (xf_\w+)\(([^;]+)\);/', $sql, $found, PREG_SET_ORDER);
foreach ($found as $match) {
    $fields=[];
    foreach (explode(',', $match[2]) as $part) $fields[]=explode(' ', trim($part), 2)[0];
    $tables[$match[1]]=$fields;
}
$pass=0;
$assert=static function (bool $condition, string $label) use (&$pass): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
    $pass++;
};
$reject=static function (callable $make, string $reason) use ($assert): void {
    try { $make(); } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $reason), $reason);
        return;
    }
    throw new RuntimeException('Unrejected '.$reason);
};
$make=static fn(array $schema,string $version)=>new XenForoSchema(new FixturePdo($schema),'xf_',$version);
$both=$make($tables,'2.3.0');
$assert($both->attachmentKeyColumn()==='file_key','2.3 probes and selects key');
$assert($both->attachmentKeySql()==='d.file_key','2.3 never falls back to obsolete file hash');
$assert(str_contains($make($tables,'2.2.15')->attachmentKeySql(),'CASE WHEN'),'2.2 mixed snapshot falls back only on empty keys');
$assert(strlen($both->fingerprint())===64,'stable schema hash');
$old=$tables; $old['xf_attachment_data']=array_diff($old['xf_attachment_data'],['file_key']);
$legacy=$make($old,'2.2.15');
$assert($legacy->attachmentKeyColumn()==='file_hash','2.2 native hash-only attachment layout');
$assert($legacy->attachmentKeySql()==='d.file_hash','legacy SQL uses actual present column');
$reject(static fn()=>$make($old,'2.3.12'),'file_key is required');
$missing=$tables;unset($missing['xf_post']);
$reject(static fn()=>$make($missing,'2.2.15'),'missing table xf_post');
$missing=$tables;$missing['xf_thread']=array_diff($missing['xf_thread'],['discussion_state']);
$reject(static fn()=>$make($missing,'2.2.15'),'thread missing required columns discussion_state');
$extra=$tables;$extra['xf_node'][]='custom_addon_flag';
$assert($make($extra,'2.3.0')->fingerprint()!==$both->fingerprint(),'extension schema drift changes immutable digest');
$assert($make($tables,'2.3.0')->fingerprint()===$both->fingerprint(),'identical snapshot stable fingerprint');
echo "XenForo PHP schema probe: $pass PASS\n";
