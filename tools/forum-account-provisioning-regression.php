<?php
/** Real Forum Edition OIDC provisioning against a disposable SQLite database.
 * Run with --require-db in CI; never substitute a mock for SQL coverage.
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/core/Console/NativeCli.php';
$autoload=dirname(__DIR__).'/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{AuditLogRepository, RoleRepository, UserRepository};
use CajeerEngine\Forum\Service\ForumOidcProvisioner;
use CajeerEngine\Identity\OidcStore;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\Request;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !extension_loaded('mbstring') || !class_exists(Request::class)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "PDO SQLite, mbstring and Symfony HttpFoundation required\n"); exit(1); }
    echo "SKIP Forum account provisioning DB integration: environment missing dependencies\n"; exit(0);
}
putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
putenv('FORUM_REGISTRATION=true');
putenv('FORUM_SPAM_REGISTRATIONS_HOUR=100');
putenv('FORUM_SPAM_REGISTRATIONS_GLOBAL_HOUR=1000');
$root=dirname(__DIR__);
$cfg=new ConfigRepository($root);
$cfg->set('database.default','sqlite');
$cfg->set('database.connections.sqlite.database',':memory:');
$db=new DatabaseManager($cfg);
$pdo=$db->connection();
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE ce_users (id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, password_hash TEXT NOT NULL, status TEXT NOT NULL, two_factor_enabled INTEGER, two_factor_recovery_codes TEXT, email_verified_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_roles (id TEXT PRIMARY KEY, handle TEXT UNIQUE NOT NULL, name TEXT NOT NULL, permissions TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE ce_user_roles (user_id TEXT NOT NULL REFERENCES ce_users(id), role_id TEXT NOT NULL REFERENCES ce_roles(id), PRIMARY KEY (user_id, role_id))");
$pdo->exec("CREATE TABLE ce_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, actor_id TEXT, context TEXT NOT NULL, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (['0036_forum_antispam.sql','0044_forum_community_settings.sql','0061_engine_skif4er_oidc.sql','0062_engine_oidc_account_linking.sql'] as $migration) {
    $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration));
}
$roles=new RoleRepository($db);
$roles->upsert('forum-member','Участник',['forum:write']);
$users=new UserRepository($db,$roles);
$passwords=new PasswordHasher($cfg);
$audit=new AuditLogRepository($db);
$issuer=ForumOidcProvisioner::ISSUER;
$store=new OidcStore($db,new SecretVault('base64:'.base64_encode(random_bytes(32))));
$service=new ForumOidcProvisioner($db,$store,$users,$roles,$passwords,$audit);
$request=Request::create('https://forum.test/auth/oidc/callback','GET',[],[],[],['REMOTE_ADDR'=>'127.0.0.1']);
$count=0;
$ok=static function (bool $condition,string $label)use(&$count):void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    $count++;echo "PASS $label\n";
};
$reject=static function (callable $test,string $label)use($ok):void {
    try { $test(); } catch (Throwable) { $ok(true,$label); return; }
    throw new RuntimeException('FAIL '.$label);
};
$claims=static fn (string $sub,string $email):array => ['iss'=>$issuer,'sub'=>$sub,'email'=>$email,'email_verified'=>true,'preferred_username'=>'Новый участник'];
$first=$service->provision($issuer,$claims('subject-1','NEW@Example.test'),$request);
$ok($first['created']===true,'first OIDC login created an account');
$uid=$first['user_id'];
$user=$users->findById($uid);
$ok($user !== null && $user['status']==='active' && $user['email']==='new@example.test' && $user['email_verified_at']!==null,'account active with verified external email');
$ok($roles->permissionsForUser($uid)===['forum:write'],'only forum-member permission granted');
$ok($store->statusForUser($issuer,$uid)['provisioned']===true,'verified identity linked as OIDC-provisioned');
$ok($service->provision($issuer,$claims('subject-1','NEW@Example.test'),$request)===['user_id'=>$uid,'created'=>false],'same subject is idempotent');
$ok($pdo->query('SELECT COUNT(*) FROM ce_users')->fetchColumn()==1,'idempotent login did not duplicate users');
$ok((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.member.register'")->fetchColumn()===1,'one transactional forum registration event');
$reject(fn()=>$store->unlink($issuer,$uid),'sole OIDC login cannot be unlinked');
$reject(fn()=>$service->provision($issuer,$claims('subject-2','new@example.test'),$request),'email collision never auto-links existing user');
$unverified=$claims('subject-3','other@example.test');$unverified['email_verified']=false;
$reject(fn()=>$service->provision($issuer,$unverified,$request),'unverified email rejected');
$badIssuer=$claims('subject-3','other@example.test');$badIssuer['iss']='https://evil.test';
$reject(fn()=>$service->provision($issuer,$badIssuer,$request),'untrusted issuer rejected');
$pdo->exec("UPDATE ce_forum_community_settings SET payload='{\"registration_enabled\":false}' WHERE id=1");
$reject(fn()=>$service->provision($issuer,$claims('subject-4','closed@example.test'),$request),'database registration policy blocks new account');
$ok($service->provision($issuer,$claims('subject-1','NEW@Example.test'),$request)['user_id']===$uid,'previously linked account still works when registration closed');
$pdo->exec("UPDATE ce_forum_community_settings SET payload='{}' WHERE id=1");
putenv('FORUM_REGISTRATION=false');
$reject(fn()=>$service->provision($issuer,$claims('subject-5','closed-operator@example.test'),$request),'operator ceiling blocks new account');
putenv('FORUM_REGISTRATION=true');
$roles->upsert('forum-member','Danger',['*']);
$reject(fn()=>$service->provision($issuer,$claims('subject-6','privileged@example.test'),$request),'unsafe forum-member role fails closed');
$roles->upsert('forum-member','Member',['forum:write']);
$pdo->exec("CREATE TRIGGER deny_oidc_provision BEFORE INSERT ON ce_oidc_identities WHEN NEW.subject='subject-7' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
$reject(fn()=>$service->provision($issuer,$claims('subject-7','rollback@example.test'),$request),'identity insert failure aborts creation');
$ok($users->findByEmail('rollback@example.test')===null,'failed OIDC mapping leaves no orphan user');
$ok((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.member.register'")->fetchColumn()===1,'failed mapping leaves no false registration audit');
putenv('FORUM_SPAM_REGISTRATIONS_HOUR=1');
$reject(fn()=>$service->provision($issuer,$claims('subject-rate','throttled@example.test'),$request),'registration rate limit rejects further OIDC signups');
putenv('FORUM_SPAM_REGISTRATIONS_HOUR=100');
$ok($pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok','SQLite integrity check passes');
$ok(ForumOidcProvisioner::displayName(['preferred_username'=>'  Иван   Петров  '], 'x')==='Иван Петров','display name normalization');
$ok(str_starts_with(ForumOidcProvisioner::displayName(['name'=>"\x00"],'subject-x'),'Участник-'),'invalid provider name falls back to neutral name');
echo "Forum OIDC account provisioning: $count PASS\n";
