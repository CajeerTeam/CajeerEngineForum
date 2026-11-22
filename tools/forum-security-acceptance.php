<?php
/**
 * Read-only production admission gate for Forum security and Identity integration.
 * Run against a deployed and already-migrated staging/production installation.
 * No accounts, sessions, tokens or database schema are mutated.
 *
 * php tools/forum-security-acceptance.php --json
 * APP_ENV=testing php tools/forum-security-acceptance.php --offline
 */
declare(strict_types=1);

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumVersion;
use CajeerEngine\Identity\IdentityAcceptance;
use CajeerEngine\Runtime\ConfigRepository;

$root = dirname(__DIR__);
if (!is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"FAIL: composer install is required before production acceptance.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';

$offline = in_array('--offline', $argv, true);
$json = in_array('--json', $argv, true);
if ($offline && in_array(strtolower((string)(getenv('APP_ENV') ?: 'production')), ['production', 'prod'], true)) {
    fwrite(STDERR,"FAIL: production acceptance cannot skip IdP and public-site TLS probes.\n");
    exit(2);
}
$results=[];
$check=static function (string $key, bool $passed, string $reason) use (&$results):void {
    $results[$key]=['ok'=>$passed,'detail'=>$reason];
};
try {
    $version=(new ForumVersion($root))->current();
    $check('edition_version', $version==='1.1.6', 'Installed Forum Edition must be 1.1.6 (got '.$version.').');
    $config=new ConfigRepository($root);
    $database=new DatabaseManager($config);
    $pdo=$database->connection();
    $driver=$database->migrationDriver();
    // Read the installed ledger directly. MigrationRunner::status() can create
    // a missing ledger table on legacy installs; acceptance must never do that.
    if (!$database->tableExists('ce_migrations')) throw new \RuntimeException('Migration ledger not initialized. Run migrations before acceptance.');
    $ledger = [];
    foreach ($pdo->query('SELECT migration, checksum, status FROM ce_migrations')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $ledger[(string)$row['migration']] = $row;
    }
    $sqlFiles = glob($root.'/migrations/'.$driver.'/*.sql') ?: [];
    if ($sqlFiles === []) throw new \RuntimeException('No installed SQL migrations found for '.$driver);
    $pending = $failed = $drift = 0;
    $seen = [];
    foreach ($sqlFiles as $file) {
        $name = basename($file);
        $seen[$name] = true;
        if (!isset($ledger[$name])) { $pending++; continue; }
        $row=$ledger[$name];
        if (($row['status'] ?? '') !== 'applied') $failed++;
        $sha=hash_file('sha256', $file);
        if ($sha === false || (($row['checksum'] ?? '') !== '' && !hash_equals((string)$row['checksum'], $sha))) $drift++;
    }
    foreach ($ledger as $name=>$row) if (!isset($seen[$name])) $drift++;
    $check('database_migrations', $pending===0 && $failed===0 && $drift===0,
        'Pending='.$pending.', failed='.$failed.', checksum/missing='.$drift);
    foreach (['ce_users','ce_api_tokens','ce_user_sessions','ce_oidc_identities',
        'ce_oidc_transactions','ce_oidc_logout_replay','ce_forum_boards',
        'ce_forum_posts','ce_forum_board_permissions','ce_forum_category_permissions','ce_forum_sanctions'] as $table) {
        $check('schema_'.$table, $database->tableExists($table), 'Required runtime table: '.$table);
    }
    // Integrity checks must read deployed data, not infer success from table presence.
    // Missing foreign keys on restored databases must not mask orphaned identities.
    $orphanLinks=(int)$pdo->query('SELECT COUNT(*) FROM ce_oidc_identities i LEFT JOIN ce_users u ON u.id=i.user_id WHERE u.id IS NULL')->fetchColumn();
    $check('oidc_link_owners',$orphanLinks===0,'External identities without a local owner: '.$orphanLinks);
    $orphanSessions=(int)$pdo->query('SELECT COUNT(*) FROM ce_user_sessions s LEFT JOIN ce_users u ON u.id=s.user_id WHERE u.id IS NULL')->fetchColumn();
    $check('session_owners',$orphanSessions===0,'Sessions without a local owner: '.$orphanSessions);
    $badSso=(int)$pdo->query("SELECT COUNT(*) FROM ce_user_sessions WHERE provider='skif4er' AND (issuer IS NULL OR issuer <> 'https://id.skif4er.ru/realms/skif4er' OR (oidc_sid_hash IS NULL AND oidc_sub_hash IS NULL))")->fetchColumn();
    $check('sso_session_provenance',$badSso===0,'SSO sessions with missing/wrong issuer or logout identity: '.$badSso);
    $revocationDrift=(int)$pdo->query('SELECT COUNT(*) FROM ce_user_sessions s JOIN ce_api_tokens t ON t.id=s.token_id WHERE t.revoked_at IS NOT NULL AND s.revoked_at IS NULL')->fetchColumn();
    $check('session_revocation_consistency',$revocationDrift===0,'Revoked tokens with still-active session registry entries: '.$revocationDrift);
    $stmt=$pdo->prepare('SELECT permissions FROM ce_roles WHERE handle = :handle');
    $stmt->execute(['handle'=>'forum-member']);
    $scopes=$stmt->fetchColumn();
    $parsed=is_string($scopes)?json_decode($scopes,true):null;
    $check('forum_member_least_privilege', $parsed===['forum:write'],
        'New external users must not inherit moderator or administrative permissions.');
    $identity=(new IdentityAcceptance($config,$database))->run(true,!$offline,!$offline);
    foreach ($identity['checks'] as $name=>$result) {
        $check('identity_'.$name, (bool)$result['ok'],(string)$result['detail']);
    }
} catch (Throwable $e) {
    $check('runtime',false,$e->getMessage());
}
$fail=array_keys(array_filter($results,static fn(array $r):bool=>!$r['ok']));
$report=['ok'=>$fail===[],'checks'=>$results,'failed_checks'=>$fail, 'mode'=>$offline?'offline-test':'production'];
if ($json) echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
else foreach ($results as $name=>$item) echo ($item['ok']?'PASS ':'FAIL ').$name.' — '.$item['detail']."\n";
exit($fail===[]?0:1);
