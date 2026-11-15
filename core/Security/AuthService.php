<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Request;

final readonly class AuthService
{
    public function __construct(
        private ConfigRepository $config,
        private UserRepository $users,
        private ApiTokenRepository $tokens,
        private AuditLogRepository $audit,
        private PasswordHasher $passwords,
        private TwoFactorTotp $totp,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password, ?string $twoFactorCode, Request $request): array
    {
        $user = $this->users->findByEmailForAuth($email);
        if ($user === null) {
            $this->audit->record('auth.login_failed', null, ['reason'=>'invalid_credentials'], $request->getClientIp(), $request->headers->get('User-Agent'));
            throw new \RuntimeException('Неверный email или пароль.');
        }
        $hit=(new RateLimiter($this->config->rootPath()))->hit('auth-account',(string)$user['id'],max(5,$this->config->int('security.rate_limits.auth',20)),60);
        if (!$hit['allowed']) throw new \CajeerEngine\Forum\ForumException('Превышен лимит попыток входа.',429);
        // Re-read credentials after locking, so a password reset cannot race session issuance.
        try {
            return $this->users->locked((string)$user['id'], function () use ($email, $password, $twoFactorCode, $request): array {
                $user = $this->users->findByEmailForAuth($email);
                if ($user === null || !$this->passwords->verify($password, (string)$user['password_hash'])) {
                    throw new \RuntimeException('Неверный email или пароль.');
                }
                if (($user['status'] ?? 'active') !== 'active') {
                    throw new \RuntimeException('Аккаунт не активирован или отключён.');
                }
                if (filter_var($user['two_factor_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $secret = (string)($user['two_factor_secret'] ?? '');
                    if ($secret === '' || $twoFactorCode === null || !$this->verifyTwoFactorCode((string)$user['id'], $secret, $twoFactorCode)) {
                        throw new \RuntimeException('Требуется корректный 2FA-код.');
                    }
                }
                $scopes = $this->users->permissionsForUser((string)$user['id']);
                $ttlDays = max(1, $this->config->int('security.sessions.ttl_days', 14));
                $token = $this->tokens->create((string)$user['id'], 'admin-session', $scopes, new \DateTimeImmutable('+' . $ttlDays . ' days'));
                $this->users->markLogin((string)$user['id']);
                $this->audit->record('auth.login_success', (string)$user['id'], ['email'=>$user['email'],'token_id'=>$token['id']], $request->getClientIp(), $request->headers->get('User-Agent'));
                $suspended=$this->users->forumSuspended((string)$user['id']);
                $publicUser=$this->publicUser($this->users->findById((string)$user['id']) ?? $user);
                if($suspended)$publicUser['permissions']=[];
                return [
                    'access_token'=>$token['plain_token'],
                    'token_type'=>'Bearer',
                    'expires_at'=>$token['expires_at'] ?? null,
                    'user'=>$publicUser,
                    'scopes'=>$suspended?[]:$scopes,
                    'suspended'=>$suspended,
                ];
            });
        } catch (\PDOException|\CajeerEngine\Database\DatabaseUnavailable $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // Record failures outside the rolled-back login transaction.
            $this->audit->record('auth.login_failed',(string)$user['id'],['reason'=>'authentication_failed'],$request->getClientIp(),$request->headers->get('User-Agent'));
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function authenticateRequest(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
            $token = trim($m[1]);
        } elseif ($authorization !== '') { return null; }
        if ($token === '') {
            $token = (string) $request->headers->get('X-API-Token', '');
        }
        if ($token === '') {
            $token = (string) $request->cookies->get('ce_session', '');
            $cookie = true;
        }
        if ($token === '') { return null; }
        $cookie = $cookie ?? false;

        $record = $this->tokens->findValidPlainToken($token);
        if ($record === null) {
            return null;
        }
        $user = isset($record['user_id']) && $record['user_id'] !== null ? $this->users->findById((string) $record['user_id']) : null;
        if ($user === null || ($user['status'] ?? '') !== 'active') { return null; }
        if ($cookie && $record['name'] !== 'admin-session') { return null; }
        $scopes = $record['scopes'];
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }
        if ($scopes === ['*'] || in_array('*', is_array($scopes) ? $scopes : [], true)) {
            $scopes = ['*'];
        }
        // A token is an upper bound, never a grant beyond current role permissions.
        $scopes = self::intersectScopes(is_array($scopes) ? $scopes : [], $this->users->permissionsForUser((string) $user['id']));
        $suspended=$this->users->forumSuspended((string)$user['id']);
        $publicUser=$this->publicUser($user);
        if($suspended){$scopes=[];$publicUser['permissions']=[];}
        $this->tokens->touch((string) $record['id']);
        return [
            'token_id' => (string) $record['id'],
            'session' => $record['name'] === 'admin-session',
            'suspended' => $suspended,
            'cookie' => $cookie,
            'csrf_token' => $cookie ? self::csrfToken($token) : null,
            'user' => $publicUser,
            'user_id' => $record['user_id'] ?? null,
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
        ];
    }

    public static function csrfToken(string $token): string
    {
        return hash('sha256', 'cajeer-csrf-v1:' . $token);
    }

    public static function intersectScopes(array $token, array $current): array
    {
        if (in_array('*', $token, true)) { return array_values($current); }
        if (in_array('*', $current, true)) { return array_values($token); }
        return array_values(array_intersect($token, $current));
    }

    /** @param list<string> $required */
    public function hasScopes(?array $identity, array $required): bool
    {
        if ($required === []) {
            return true;
        }
        if ($identity === null) {
            return false;
        }
        $scopes = $identity['scopes'] ?? [];
        if (!is_array($scopes)) {
            return false;
        }
        if (in_array('*', $scopes, true)) {
            return true;
        }
        foreach ($required as $scope) {
            if (!in_array($scope, $scopes, true)) {
                return false;
            }
        }
        return true;
    }

    public function logout(Request $request): bool
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || !isset($identity['token_id'])) {
            return false;
        }
        $this->tokens->revoke((string) $identity['token_id']);
        $this->audit->record('auth.logout', isset($identity['user_id']) ? (string) $identity['user_id'] : null, ['token_id' => $identity['token_id']], $request->getClientIp(), $request->headers->get('User-Agent'));
        return true;
    }

    public function confirmSensitiveAction(array $identity, string $password, ?string $code): bool
    {
        if (!($identity['session'] ?? false) || empty($identity['user']['email']) || $password === '' || strlen($password)>1024) return false;
        $user = $this->users->findByEmailForAuth((string) $identity['user']['email']);
        if ($user === null || $user['status'] !== 'active' || !$this->passwords->verify($password, (string)$user['password_hash'])) return false;
        return !filter_var($user['two_factor_enabled'] ?? false,FILTER_VALIDATE_BOOLEAN)
            || ($code !== null && $this->verifyTwoFactorCode((string)$user['id'],(string)$user['two_factor_secret'],$code));
    }

    /** @return array<string, mixed> */
    private function publicUser(array $user): array
    {
        unset($user['password_hash'], $user['two_factor_secret'], $user['two_factor_recovery_codes'], $user['two_factor_last_step']);
        $user['permissions'] = isset($user['id']) ? $this->users->permissionsForUser((string) $user['id']) : [];
        return $user;
    }

    private function verifyTwoFactorCode(string $userId, string $secret, string $code): bool
    {
        $step = $this->totp->matchingStep($secret, $code);
        if ($step !== null) return $this->users->acceptTotpStep($userId, $step);
        return $this->users->consumeRecoveryCode($userId, $code);
    }
}
