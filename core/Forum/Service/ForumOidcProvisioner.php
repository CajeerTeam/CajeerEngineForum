<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\AntiSpamRepository;
use CajeerEngine\Identity\OidcStore;
use CajeerEngine\Extension\Runtime\PlatformServices;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\Request;

/** Forum account creation from a cryptographically verified OIDC ID token only.
 * The caller (OidcLogin) must use OidcClient::finish before invoking this service.
 * No email or user-name based account linking is performed.
 */
final readonly class ForumOidcProvisioner
{
    public const ISSUER = 'https://id.skif4er.ru/realms/skif4er';

    public function __construct(
        private DatabaseManager $database,
        private OidcStore $store,
        private UserRepository $users,
        private RoleRepository $roles,
        private PasswordHasher $passwords,
        private AuditLogRepository $audit,
        private ?PlatformServices $platform = null,
    ) {}

    /** @return array{user_id:string,created:bool} */
    public function provision(string $issuer, array $claims, Request $request): array
    {
        if ($issuer !== self::ISSUER || ($claims['iss'] ?? null) !== $issuer) {
            throw new \RuntimeException('Untrusted identity issuer.');
        }
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $subject)
            || ($claims['email_verified'] ?? null) !== true
            || !is_string($email) || !mb_check_encoding($email, 'UTF-8')
            || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ForumException('Для регистрации нужен подтверждённый адрес email SkiF4er ID.', 403);
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        $name = self::displayName($claims, $subject);
        $tx = new ForumTransaction($this->database, $this->audit, $this->platform);
        if (!ForumCommunitySettings::operatorRegistration()
            || !$tx->settings()->snapshot()['values']['registration_open']) {
            throw new ForumException('Регистрация на форуме отключена.', 403);
        }

        // Same per-IP/global registration throttling as the local forum form.
        // This is intentionally outside the account-creation transaction so rejected
        // attempts still count towards the registration quota.
        $tx->antiSpam()->registration($request->getClientIp() ?? 'unknown');

        return $tx->write(function () use ($tx, $issuer, $subject, $email, $name): array {
            // A shared registration row serializes provisioning across PHP workers
            // and prevents duplicate profile creation from concurrent OIDC callbacks.
            (new AntiSpamRepository($this->database))->registrationLock();
            $existing = $this->store->linkedUser($issuer, $subject);
            if ($existing !== null) return ['user_id'=>$existing, 'created'=>false];

            if (!ForumCommunitySettings::operatorRegistration()
                || !$tx->settings()->snapshot(true)['values']['registration_open']) {
                throw new ForumException('Регистрация на форуме отключена.', 403);
            }
            if ($this->users->findByEmail($email) !== null) {
                // Verified email alone is NOT proof of local-account ownership.
                throw new ForumException('Аккаунт с этим email уже существует. Войдите локально и привяжите SkiF4er ID.', 409);
            }
            $member = $this->roles->find('forum-member');
            if ($member === null || $member['permissions'] !== ['forum:write']) {
                throw new \RuntimeException('Forum member role missing or unsafe.');
            }
            // Do not silently create a usable local password for an SSO-only account.
            // It can be enabled later through a separate verified recovery process.
            $password = $this->passwords->hash('A9a!' . bin2hex(random_bytes(48)));
            $id = $this->users->create($email, $name, $password, ['forum-member'], 'active');
            $pdo = $this->database->connection();
            $verified = $pdo->prepare('UPDATE ce_users SET email_verified_at=CURRENT_TIMESTAMP WHERE id=:id AND email_verified_at IS NULL');
            $verified->execute(['id'=>$id]);
            if ($verified->rowCount() !== 1) throw new \RuntimeException('Could not confirm verified OIDC email.');
            $link = $pdo->prepare('INSERT INTO ce_oidc_identities (issuer, subject, user_id, provisioned) VALUES (:issuer, :subject, :user, :provisioned)');
            $link->execute(['issuer'=>$issuer, 'subject'=>$subject, 'user'=>$id, 'provisioned'=>1]);
            $tx->audit('forum.member.register', $id, ['source'=>'skif4er']);
            return ['user_id'=>$id, 'created'=>true];
        });
    }

    /** Only human-readable, bounded, UTF-8 names; never trust provider-side roles. */
    public static function displayName(array $claims, string $subject): string
    {
        foreach (['preferred_username','name'] as $key) {
            $candidate = $claims[$key] ?? null;
            if (!is_string($candidate) || !mb_check_encoding($candidate, 'UTF-8')) continue;
            $candidate = preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $candidate);
            if (!is_string($candidate)) continue;
            $candidate = trim($candidate);
            if (mb_strlen($candidate, 'UTF-8') >= 2 && mb_strlen($candidate, 'UTF-8') <= 60) return $candidate;
        }
        return 'Участник-' . substr(hash('sha256', $subject), 0, 12);
    }
}
