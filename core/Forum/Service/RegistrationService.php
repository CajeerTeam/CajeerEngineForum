<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Security\PasswordHasher;

final readonly class RegistrationService
{
    public function __construct(private ForumTransaction $tx, private UserRepository $users, private PasswordHasher $passwords) {}
    public static function verificationRequired(): bool
    {
        return getenv('FORUM_REQUIRE_EMAIL_VERIFICATION') !== 'false';
    }
    public function checkAttempt(string $ip,mixed $trap): void
    { $this->tx->antiSpam()->registration($ip);if($trap!==null&&$trap!=='')throw new ForumException('Регистрация невозможна.',422); }
    public function register(string $email, string $name, string $password): string
    {
        $email = V::text($email,'email',254); $name = V::text($name,'name',60,2); $password = V::password($password);
        if (!ForumCommunitySettings::operatorRegistration()) throw new ForumException('Регистрация отключена.',403);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ForumException('Некорректный email пользователя.');
        try { $hash = $this->passwords->hash($password); }
        catch (\InvalidArgumentException $e) { throw new ForumException($e->getMessage()); }
        try {
            return $this->tx->write(function () use ($email,$name,$hash) {
                if (!$this->tx->settings()->snapshot(true)['values']['registration_open']) throw new ForumException('Регистрация отключена.',403);
                if ($this->users->findByEmail($email) !== null) throw new ForumException('Регистрация невозможна.',409);
                try { $id = $this->users->create($email,$name,$hash,['forum-member'],self::verificationRequired() ? 'pending' : 'active'); }
                catch (\InvalidArgumentException $e) {
                    if ($this->users->findByEmail($email) !== null) throw new ForumException('Регистрация невозможна.',409);
                    throw new \LogicException('Forum registration configuration invalid', 0, $e);
                }
                $this->tx->audit('forum.member.register',$id,[]);
                return $id;
            });
        } catch (\PDOException $e) {
            $state = (string) $e->getCode(); $native = (int) ($e->errorInfo[1] ?? 0);
            if ($state === '23505' || ($state === '23000' && ($native === 1062 || ($native === 19 && str_contains($e->getMessage(),'ce_users.email')) || $native === 2067))) throw new ForumException('Регистрация невозможна.',409);
            throw $e;
        }
    }
}
