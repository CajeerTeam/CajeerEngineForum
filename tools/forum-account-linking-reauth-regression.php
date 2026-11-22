<?php
/** Guard against confirming another account's password for a stale session identity. */
declare(strict_types=1);
namespace Symfony\Component\HttpFoundation { class Request {} }
namespace CajeerEngine\Runtime { class ConfigRepository {} }
namespace CajeerEngine\Database\Repository {
    class UserRepository {
        public array $user=['id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','status'=>'active','password_hash'=>'test_hash','two_factor_enabled'=>false];
        public function findByEmailForAuth(string $email): ?array { return $this->user; }
    }
    class ApiTokenRepository {}
    class AuditLogRepository {}
    class UserSessionRepository {}
}
namespace CajeerEngine\Security {
    class PasswordHasher { public function verify(string $password, string $hash): bool { return $password === 'correct-password' && $hash === 'test_hash'; } }
    class TwoFactorTotp {}
}
namespace CajeerEngine\Identity { class OidcApiAuthenticator {} }
namespace {
    require_once dirname(__DIR__).'/core/Security/AuthService.php';
    $users = new \CajeerEngine\Database\Repository\UserRepository();
    $auth = new \CajeerEngine\Security\AuthService(new \CajeerEngine\Runtime\ConfigRepository(), $users,
        new \CajeerEngine\Database\Repository\ApiTokenRepository(), new \CajeerEngine\Database\Repository\AuditLogRepository(),
        new \CajeerEngine\Security\PasswordHasher(), new \CajeerEngine\Security\TwoFactorTotp());
    $current = ['session'=>true,'user_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','user'=>['email'=>'old@example.test']];
    $count=0;
    function check(bool $condition,string $label): void { global $count; if (!$condition)throw new \RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label."\n"; }
    check(!$auth->confirmSensitiveAction($current,'correct-password',null),'different UUID cannot reauthenticate stale email session');
    $current['user_id']=$users->user['id'];
    check($auth->confirmSensitiveAction($current,'correct-password',null),'matching UUID and correct local password allowed');
    check(!$auth->confirmSensitiveAction($current,'wrong-password',null),'wrong password cannot authorize a link');
    $users->user['status']='disabled';
    check(!$auth->confirmSensitiveAction($current,'correct-password',null),'disabled user cannot reauthenticate');
    check(!$auth->confirmSensitiveAction(['session'=>false,'user_id'=>$users->user['id'],'user'=>['email'=>'old@example.test']],'correct-password',null),'bearer token cannot satisfy local session reauthentication');
    require_once dirname(__DIR__).'/core/Identity/OidcStore.php';
    $boolean=new \ReflectionMethod(\CajeerEngine\Identity\OidcStore::class,'databaseBoolean');
    foreach (['f'=>false,'t'=>true,'false'=>false,'true'=>true,'0'=>false,'1'=>true] as $dbValue=>$expected) {
        check($boolean->invoke(null,(string)$dbValue)===$expected,'database boolean '.$dbValue.' normalized for safe unlink');
    }
    require_once dirname(__DIR__).'/core/Api/ApiContract.php';
    $contract=new \CajeerEngine\Api\ApiContract(dirname(__DIR__));
    $op=$contract->operation('api.v1.identity.link.start');
    $schema=$op['requestBody']['content']['application/json']['schema'];
    check($contract->validate(json_decode('{"password":"strong-password","return_to":"/forum/account/security"}'),$schema)===[], 'forum link POST passes actual runtime OpenAPI validation');
    check($contract->validate(json_decode('{"password":"strong-password","return_to":"https://evil.test"}'),$schema)!==[], 'external continuation rejected by runtime OpenAPI');
    check($contract->validate(json_decode('{"password":"strong-password","user_id":"attacker-selected"}'),$schema)!==[], 'browser cannot submit a target UUID through OpenAPI');
    echo "Forum Account Linking reauthentication: $count PASS\n";
}
