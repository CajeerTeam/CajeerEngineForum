<?php
/** Exercise the production AuthController for real sensitive-account actions, without Composer. */
declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    final class TestBag {
        public function __construct(private array $data = []) {}
        public function get(string $name, mixed $default = null): mixed { return $this->data[$name] ?? $default; }
    }
    class Request {
        public TestBag $attributes;
        public function __construct(array $auth, array $json = []) { $this->attributes = new TestBag(['auth'=>$auth, 'json'=>$json]); }
    }
    class Response { public const HTTP_CREATED=201; public const HTTP_UNAUTHORIZED=401; public const HTTP_UNPROCESSABLE_ENTITY=422; }
    final class JsonResponse {
        public function __construct(public array $data = [], private int $status=200, private array $headers=[]) {}
        public function getStatusCode(): int { return $this->status; }
    }
}
namespace CajeerEngine\Security {
    final class AuthService {
        public int $passwordChecks=0, $changes=0;
        public bool $passwordAccepted = true;
        public function confirmSensitiveAction(array $identity, string $password, ?string $code): bool {
            $this->passwordChecks++;
            return $this->passwordAccepted && $password === 'password';
        }
        public function enableTwoFactor(string $userId): array { $this->changes++; return ['secret'=>'private','recovery_codes'=>[]]; }
        public function disableTwoFactor(string $userId): void { $this->changes++; }
    }
    final class AuthenticationAssurance {
        public array $result=['provider'=>'skif4er','fresh'=>false];
        public bool $broken=false;
        public function status(array $identity): array {
            if ($this->broken) throw new \RuntimeException('Database unavailable');
            return $this->result;
        }
    }
}
namespace {
    require dirname(__DIR__).'/core/Api/Controller/AuthController.php';
    $n=0;
    $assert = static function (bool $ok, string $label) use (&$n): void {
        if (!$ok) throw new \RuntimeException('FAIL '.$label);
        $n++;
        echo 'PASS '.$label."\n";
    };
    $auth = new \CajeerEngine\Security\AuthService();
    $assurance = new \CajeerEngine\Security\AuthenticationAssurance();
    $controller = new \CajeerEngine\Api\Controller\AuthController($auth,$assurance);
    $session = ['user_id'=>'account','session'=>true,'cookie'=>true];
    $request = static fn(array $id = [], string $password = 'password'): \Symfony\Component\HttpFoundation\Request =>
        new \Symfony\Component\HttpFoundation\Request($id ?: $session,['password'=>$password]);

    $assert($controller->disableTwoFactor($request())->getStatusCode()===403, 'unassured SSO cannot disable MFA using local password');
    $assert($controller->enableTwoFactor($request())->getStatusCode()===403, 'unassured SSO cannot enable MFA using local password');
    $assert($auth->passwordChecks===0 && $auth->changes===0, 'SSO credentials never enter local password fallback');

    $assurance->result=['provider'=>'skif4er','fresh'=>true];
    $assert($controller->enableTwoFactor($request())->getStatusCode()===201 && $auth->changes===1, 'fresh verified OIDC MFA permits enrollment');
    $assert($controller->disableTwoFactor($request())->getStatusCode()===200 && $auth->changes===2, 'fresh verified OIDC MFA permits disabling');
    $assert($auth->passwordChecks===0, 'OIDC assurance needs no local password');

    $assurance->result=['provider'=>'local','fresh'=>false];
    $assert($controller->enableTwoFactor($request())->getStatusCode()===201 && $auth->passwordChecks===1, 'local account can reauthenticate with own password');
    $auth->passwordAccepted=false;
    $assert($controller->disableTwoFactor($request())->getStatusCode()===403 && $auth->changes===3, 'invalid local password refused');
    $auth->passwordAccepted=true;
    $assurance->result=['provider'=>'legacy','fresh'=>false];
    $assert($controller->disableTwoFactor($request())->getStatusCode()===200, 'migrated legacy session retains verified local reauthentication');

    $assurance->result=['provider'=>null,'fresh'=>false];
    $checks=$auth->passwordChecks;
    $assert($controller->enableTwoFactor($request())->getStatusCode()===403 && $auth->passwordChecks===$checks, 'unknown session provider fails closed');
    $assurance->broken=true;
    $assert($controller->enableTwoFactor($request())->getStatusCode()===403 && $auth->passwordChecks===$checks, 'registry outage fails closed');
    $assurance->broken=false;
    $assurance->result=['provider'=>'skif4er','fresh'=>true];
    $assert($controller->disableTwoFactor($request(['user_id'=>'account','session'=>true,'cookie'=>false]))->getStatusCode()===403, 'non-browser bearer tokens cannot alter MFA through assurance');
    echo "Forum 1.0.9 sensitive account controller: {$n} PASS\n";
}
