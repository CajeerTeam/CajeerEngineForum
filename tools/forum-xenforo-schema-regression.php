<?php
/** Executable XenForo 2.2/2.3 source compatibility acceptance (PDO SQLite). */
declare(strict_types=1);

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Migration\ForumMigrationEngine;
use CajeerEngine\Forum\Migration\MultiForumSource;
use CajeerEngine\Runtime\ConfigRepository;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root.'/core/'.str_replace('\\', '/', substr($class, 13)).'.php';
        if (is_file($file)) require $file;
    }
});
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Required test environment: pdo_sqlite\n");
    exit(1);
}
$pass = 0;
$assert = static function (bool $ok, string $message) use (&$pass): void {
    if (!$ok) throw new RuntimeException($message);
    $pass++;
    echo "PASS $message\n";
};
$reject = static function (callable $operation, string $needle) use ($assert): void {
    try { $operation(); } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $needle), 'fail closed: '.$needle);
        return;
    }
    throw new RuntimeException('Expected source rejection: '.$needle);
};
$open = static function () use ($root): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));
    return $db;
};
$adapter = static fn(PDO $source, string $version) => new MultiForumSource($source, 'xf_', 'xenforo', $version, 'frozen');

$pdo = $open();
$source = $adapter($pdo, '2.3.0');
$plan = $source->plan();
$assert(preg_match('/\A[a-f0-9]{64}\z/D', $plan['source_schema_sha256']) === 1, 'new jobs include actual XenForo schema digest');
$assert($plan['source_attachment_key'] === 'file_key', 'XF 2.3 uses file_key');
$assert($source->batch('attachments', 0, 40, 1)[0]['physical_filename'] === '1/1001-'.str_repeat('b', 32).'.data', '2.3 attachment path uses stable file_key');
$assert($source->additionalPlan()['reactions']['count'] === 6, 'source 2.3 extended reactions scanned');
$db = new DatabaseManager(new ConfigRepository($root));
$engine = new ForumMigrationEngine($db, $source);
$current = $engine->snapshotPlan();
$assert($engine->matchesRecordedPlan($current), 'new schema record matches immutable snapshot');
$changed = $current;
$changed['source_schema_sha256'] = str_repeat('0', 64);
$assert(!$engine->matchesRecordedPlan($changed), 'changed source schema digest blocks resume');
$changed = $current;
$changed['source_attachment_key'] = 'file_hash';
$assert(!$engine->matchesRecordedPlan($changed), 'changed storage mapping blocks resume');
$legacy = $current;
unset($legacy['source_schema_sha256'], $legacy['source_attachment_key']);
$assert($engine->matchesRecordedPlan($legacy), '1.1.1 recorded jobs resume without new ledger keys');
$pdo->rollBack();
unset($source, $engine, $pdo);

$pdo=$open();$pdo->exec("UPDATE xf_attachment_data SET file_key='' WHERE data_id=1001");
$source=$adapter($pdo,'2.3.0');
$assert($source->batch('attachments',0,40,1)[0]['physical_filename']==='', '2.3 blank key cannot substitute legacy hash');
$pdo->rollBack();unset($source,$pdo);

$pdo = $open();
$source = $adapter($pdo, '2.2.15');
$assert($source->batch('attachments', 0, 40, 1)[0]['physical_filename'] === '1/1001-'.str_repeat('b', 32).'.data', '2.2 upgraded snapshot prefers present file_key');
$pdo->rollBack();unset($source, $pdo);

$pdo = $open();
$pdo->exec("UPDATE xf_attachment_data SET file_key='' WHERE data_id=1001");
$source = $adapter($pdo, '2.2.15');
$assert($source->batch('attachments', 0, 40, 1)[0]['physical_filename'] === '1/1001-'.str_repeat('a', 32).'.data', '2.2 empty file_key falls back to file_hash');
$pdo->rollBack();unset($source, $pdo);

$pdo = $open();
$pdo->exec('ALTER TABLE xf_attachment_data DROP COLUMN file_key');
$source = $adapter($pdo, '2.2.15');
$assert($source->plan()['source_attachment_key'] === 'file_hash', 'native 2.2 hash-only schema accepted');
$assert($source->batch('attachments', 0, 40, 1)[0]['physical_filename'] === '1/1001-'.str_repeat('a', 32).'.data', '2.2 legacy file_hash path');
$pdo->rollBack();unset($source);
$reject(static fn() => $adapter($pdo, '2.3.0'), 'file_key is required');
unset($pdo);

$pdo = $open();$pdo->exec('ALTER TABLE xf_user_profile DROP COLUMN website');
$reject(static fn() => $adapter($pdo, '2.2.15'), 'user_profile missing required columns website');unset($pdo);
$pdo = $open();$pdo->exec('DROP TABLE xf_poll_vote');
$reject(static fn() => $adapter($pdo, '2.3.0'), 'missing table xf_poll_vote');unset($pdo);
$pdo = $open();$pdo->exec('ALTER TABLE xf_attachment_data DROP COLUMN file_hash');$pdo->exec('ALTER TABLE xf_attachment_data DROP COLUMN file_key');
$reject(static fn() => $adapter($pdo, '2.2.15'), 'requires file_hash or file_key');unset($pdo);

$pdo = $open();$a = $adapter($pdo, '2.3.0');$digest = $a->plan()['source_schema_sha256'];
$pdo->rollBack();unset($a,$pdo);
$pdo=$open();$pdo->exec('ALTER TABLE xf_user ADD COLUMN custom_addon_flag TEXT');
$b = $adapter($pdo, '2.3.0');
$assert($digest !== $b->plan()['source_schema_sha256'], 'schema fingerprint catches extra plugin columns across restart');
$pdo->rollBack();
echo "XenForo 1.1.2 source compatibility: $pass PASS\n";
