<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Support\Uuid;

final readonly class AntiSpamRepository extends Repository
{
    public function registrationLock(): void
    { if(!$this->lock('ce_forum_spam_lock','id=?',[1]))throw new \LogicException('Missing registration lock'); }
    public function user(string $id): ?array
    { return $this->row('SELECT created_at,email_verified_at FROM ce_users WHERE id=?',[$id]); }
    public function usage(string $subject,string $action,int $since): int
    { if($this->db->driver()==='mysql')return array_sum(array_map('intval',array_column($this->rows('SELECT amount FROM ce_forum_spam_events WHERE subject=? AND action=? AND created_at>? FOR UPDATE',[$subject,$action,$since]),'amount')));return (int)$this->row('SELECT COALESCE(SUM(amount),0) AS total FROM ce_forum_spam_events WHERE subject=? AND action=? AND created_at>?',[$subject,$action,$since])['total']; }
    public function registrationUsage(int $since): int
    { if($this->db->driver()==='mysql')return array_sum(array_map('intval',array_column($this->rows("SELECT amount FROM ce_forum_spam_events WHERE action='register' AND created_at>? FOR UPDATE",[$since]),'amount')));return (int)$this->row("SELECT COALESCE(SUM(amount),0) AS total FROM ce_forum_spam_events WHERE action='register' AND created_at>?",[$since])['total']; }
    public function duplicate(string $subject,string $hash,int $since): bool
    { return $this->row("SELECT id FROM ce_forum_spam_events WHERE subject=? AND action IN ('thread','reply') AND fingerprint=? AND created_at>? LIMIT 1".($this->db->driver()==='mysql'?' FOR UPDATE':''),[$subject,$hash,$since])!==null; }
    public function record(?string $user,string $subject,string $action,int $now,int $amount=1,?string $hash=null,?string $reason=null): void
    { $this->query('INSERT INTO ce_forum_spam_events(id,user_id,subject,action,created_at,amount,fingerprint,reason) VALUES(?,?,?,?,?,?,?,?)',[Uuid::v4(),$user,$subject,$action,$now,$amount,$hash,$reason]); }
    public function cleanup(int $before): int
    { $count=0;foreach($this->rows('SELECT id FROM ce_forum_spam_events WHERE created_at<=? ORDER BY created_at,id LIMIT 1000',[$before]) as $row)$count+=$this->query('DELETE FROM ce_forum_spam_events WHERE id=? AND created_at<=?',[$row['id'],$before])->rowCount();return $count; }
    public function stats(int $since): array
    {return $this->rows('SELECT action,COUNT(*) AS events,COALESCE(SUM(amount),0) AS amount FROM ce_forum_spam_events WHERE created_at>? GROUP BY action ORDER BY action',[$since]);}
    public function reasons(int $since): array
    {return $this->rows('SELECT reason,COUNT(*) AS events FROM ce_forum_spam_events WHERE reason IS NOT NULL AND created_at>? GROUP BY reason ORDER BY reason',[$since]);}
}
