<?php
declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository, ApiTokenRepository, AuditLogRepository};
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\IdentityMail;
use CajeerEngine\Security\{AuthService, PasswordHasher, TwoFactorTotp};

final readonly class IdentityService
{
    public function __construct(private DatabaseManager $db, private UserRepository $users, private ApiTokenRepository $tokens, private AuditLogRepository $audit, private PasswordHasher $passwords, private TwoFactorTotp $totp, private AuthService $auth, private IdentityMail $mail) {}

    /** Mail is sent only after commit; a failed delivery leaves a recoverable account. */
    public function requestMail(string $email, string $purpose): void
    {
        if (!in_array($purpose,['verify','reset'],true)) throw new \LogicException('Invalid purpose');
        $user = $this->users->findByEmail($email);
        if (!$user) return;
        $id=$user['id'];
        $plain=$this->users->locked($id,function() use($id,$email,$purpose) {
            $user=$this->users->findByEmailForAuth($email);
            if (!$user || $user['id']!==$id || ($purpose==='verify' && (!in_array($user['status'],['pending','active'],true) || !empty($user['email_verified_at']))) || ($purpose==='reset' && !in_array($user['status'],['active','pending'],true))) return null;
            $row=$this->challenge($id,$purpose);
            if ($row && (int)$row['issued_at']>time()-60) return null;
            return $this->issue($id,$purpose,$purpose==='verify'?86400:1800);
        });
        if (!$plain) return;
        try { $this->mail->send($email,$purpose,$plain); }
        catch (\RuntimeException) {
            // Never log token or SMTP credentials, never reveal account existence in response.
            $this->audit->record('auth.identity_mail_failed',$id,['purpose'=>$purpose]);
            return;
        }
        $this->audit->record('auth.identity_mail_sent',$id,['purpose'=>$purpose]);
    }

    public function verifyEmail(string $token,string $password): void
    {
        $this->consume($token,'verify',function(array $user) use($password) {
            if (!in_array($user['status'],['pending','active'],true) || !empty($user['email_verified_at']) || !$this->passwords->verify($password,$user['password_hash'])) throw new ForumException('Недействительная ссылка или пароль.',422);
            $this->sql("UPDATE ce_users SET status='active', email_verified_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status IN ('pending','active') AND email_verified_at IS NULL",['id'=>$user['id']]);
            $this->audit->record('auth.email_verified',$user['id'],[]);
        });
    }

    public function resetPassword(string $token,string $password): void
    {
        try { $hash=$this->passwords->hash($password); } catch (\InvalidArgumentException $e) { throw new ForumException($e->getMessage(),422); }
        $this->consume($token,'reset',function(array $user) use($hash) {
            if (!in_array($user['status'],['active','pending'],true)) throw new ForumException('Недействительная ссылка.',422);
            $this->sql('UPDATE ce_users SET password_hash=:hash,password_changed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id',['hash'=>$hash,'id'=>$user['id']]);
            $this->tokens->revokeForUser($user['id']);
            $this->remove($user['id'],'totp');
            $this->audit->record('auth.password_reset',$user['id'],[]);
        });
    }

    public function sessions(array $identity): array
    {
        $id=$this->sessionUser($identity);
        $rows=$this->sql("SELECT id,created_at,last_used_at,expires_at FROM ce_api_tokens WHERE user_id=:id AND name='admin-session' AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY created_at DESC",['id'=>$id])->fetchAll();
        foreach ($rows as &$row) $row['current']=$row['id']===$identity['token_id'];
        return $rows;
    }
    public function revokeSession(array $identity,string $session): bool
    {
        $id=$this->sessionUser($identity);
        return $this->users->locked($id,function() use($id,$session) {
            $n=$this->sql("UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=:token AND user_id=:id AND name='admin-session' AND revoked_at IS NULL",['token'=>$session,'id'=>$id])->rowCount();
            if ($n) $this->audit->record('auth.session_revoked',$id,['token_id'=>$session]);
            return $n>0;
        });
    }
    public function revokeSessions(array $identity,string $password,?string $code): void
    {
        $this->sensitive($identity,$password,$code,function($id) {
            $this->sql("UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=:id AND name='admin-session' AND revoked_at IS NULL",['id'=>$id]);
            $this->audit->record('auth.sessions_revoked',$id,[]);
        });
    }

    /** Start enrollment without changing active credentials. */
    public function beginTwoFactor(array $identity,string $password): array
    {
        return $this->sensitive($identity,$password,null,function($id,$user) {
            if (filter_var($user['two_factor_enabled'],FILTER_VALIDATE_BOOLEAN)) throw new ForumException('2FA уже включена.',409);
            $secret=$this->totp->generateSecret();
            $token=$this->issue($id,'totp',600,$secret);
            $issuer=getenv('APP_NAME') ?: 'CajeerEngine Forum';
            return ['secret'=>$secret,'setup_token'=>$token,'otpauth_uri'=>'otpauth://totp/'.rawurlencode($issuer.':'.$user['email']).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30','expires_in'=>600];
        });
    }
    public function confirmTwoFactor(array $identity,string $password,string $token,string $code): array
    {
        return $this->sensitive($identity,$password,null,function($id,$user) use($token,$code) {
            $row=$this->challenge($id,'totp');
            if (filter_var($user['two_factor_enabled'],FILTER_VALIDATE_BOOLEAN) || !$row || (int)$row['expires_at']<=time() || !hash_equals($row['token_hash'],hash('sha256',$token))) throw new ForumException('Настройка 2FA истекла.',422);
            $step=$this->totp->matchingStep($row['secret'],$code);
            if ($step===null) throw new ForumException('Некорректный 2FA-код.',422);
            $codes=$this->totp->recoveryCodes();
            $this->users->enableTwoFactor($id,$row['secret'],$this->totp->hashRecoveryCodes($codes));
            $this->users->acceptTotpStep($id,$step);
            $this->remove($id,'totp'); $this->tokens->revokeForUser($id);
            $this->audit->record('auth.2fa_enabled',$id,[]);
            return ['two_factor_enabled'=>true,'recovery_codes'=>$codes];
        });
    }
    public function disableTwoFactor(array $identity,string $password,?string $code): void
    {
        $this->sensitive($identity,$password,$code,function($id) {
            $this->users->disableTwoFactor($id); $this->remove($id,'totp'); $this->tokens->revokeForUser($id);
            $this->audit->record('auth.2fa_disabled',$id,[]);
        });
    }
    private function sensitive(array $identity,string $password,?string $code,callable $fn): mixed
    {
        $id=$this->sessionUser($identity);
        return $this->users->locked($id,function() use($id,$identity,$password,$code,$fn) {
            $token=$this->tokens->find($identity['token_id']);
            $user=$this->users->findByEmailForAuth($identity['user']['email']);
            if (!$token || $token['revoked_at']!==null || ApiTokenRepository::expiryTimestamp($token['expires_at'] ?? '')<=time() || !$user || $user['id']!==$id || !$this->auth->confirmSensitiveAction($identity,$password,$code)) throw new ForumException('Подтвердите пароль и текущий 2FA-код.',403);
            return $fn($id,$user);
        });
    }
    private function sessionUser(array $identity): string
    {
        if (!($identity['session']??false) || empty($identity['user_id']) || empty($identity['token_id'])) throw new ForumException('Требуется пользовательская сессия.',403);
        return $identity['user_id'];
    }
    private function consume(string $token,string $purpose,callable $fn): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new ForumException('Недействительная ссылка.',422);
        $hash=hash('sha256',$token);
        $row=$this->sql('SELECT user_id FROM ce_identity_challenges WHERE token_hash=:hash AND purpose=:purpose',['hash'=>$hash,'purpose'=>$purpose])->fetch();
        if (!$row) throw new ForumException('Недействительная ссылка.',422);
        $id=$row['user_id'];
        $this->users->locked($id,function() use($id,$hash,$purpose,$fn) {
            $row=$this->challenge($id,$purpose);
            $public=$this->users->findById($id); $user=$public?$this->users->findByEmailForAuth($public['email']):null;
            if (!$row || !$user || (int)$row['expires_at']<=time() || !hash_equals($row['token_hash'],$hash)) throw new ForumException('Недействительная или просроченная ссылка.',422);
            $fn($user); $this->remove($id,$purpose);
        });
    }
    private function issue(string $id,string $purpose,int $ttl,?string $secret=null): string
    {
        $plain=bin2hex(random_bytes(32)); $now=time();
        $this->remove($id,$purpose);
        $this->sql('INSERT INTO ce_identity_challenges(user_id,purpose,token_hash,secret,issued_at,expires_at) VALUES(:id,:purpose,:hash,:secret,:issued,:expires)',['id'=>$id,'purpose'=>$purpose,'hash'=>hash('sha256',$plain),'secret'=>$secret,'issued'=>$now,'expires'=>$now+$ttl]);
        return $plain;
    }
    private function remove(string $id,string $purpose): void { $this->sql('DELETE FROM ce_identity_challenges WHERE user_id=:id AND purpose=:purpose',['id'=>$id,'purpose'=>$purpose]); }
    private function challenge(string $id,string $purpose): ?array { $row=$this->sql('SELECT * FROM ce_identity_challenges WHERE user_id=:id AND purpose=:purpose',['id'=>$id,'purpose'=>$purpose])->fetch();return $row?:null; }
    private function sql(string $sql,array $params): \PDOStatement { $s=$this->db->connection()->prepare($sql);$s->execute($params);return $s; }
}
