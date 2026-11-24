<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use PDO;

/** Bounded, database-wide FIFO admission for synchronous/SSE requests. No prompt is queued. */
final readonly class ForumAiAdmission
{
    public const ACTIVE = 4;
    public const WAITING = 16;
    public const WAIT_SECONDS = 2;
    public const LEASE_SECONDS = 240;

    public function __construct(private DatabaseManager $db) {}

    private function lock(PDO $pdo): void
    {
        // UPDATE acquires a write lock before any snapshot reads, including SQLite.
        $pdo->exec('UPDATE ce_forum_ai_admission_state SET cooldown_until = cooldown_until WHERE id = 1');
        if($pdo->query('SELECT id FROM ce_forum_ai_admission_state WHERE id = 1')->fetchColumn()===false)throw new \RuntimeException('Forum AI admission state missing.');
    }

    public function acquire(string $userId): string
    {
        if ($this->db->connection()->inTransaction()) throw new \LogicException('AI admission cannot wait inside a transaction.');
        $id=bin2hex(random_bytes(16));
        $deadline=hrtime(true)+self::WAIT_SECONDS*1000000000;
        try {
            do {
                $granted=$this->db->transaction(function(PDO $pdo) use($id,$userId):bool {
                    $this->lock($pdo);$now=time();
                    $pdo->prepare('DELETE FROM ce_forum_ai_admission WHERE expires_at <= ?')->execute([$now]);
                    if((int)$pdo->query('SELECT cooldown_until FROM ce_forum_ai_admission_state WHERE id = 1')->fetchColumn()>$now)
                        throw new ForumException('AI временно недоступен. Повторите позднее.',503);
                    $q=$pdo->prepare('SELECT id FROM ce_forum_ai_admission WHERE user_id = ?');$q->execute([$userId]);$existing=$q->fetchColumn();
                    if($existing!==false && $existing!==$id)throw new ForumException('Один AI-запрос уже выполняется или ожидает.',429);
                    if($existing===false){
                        if((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active = 0')->fetchColumn()>=self::WAITING)
                            throw new ForumException('Очередь AI заполнена. Повторите позднее.',429);
                        // Monotonic ordering under the singleton lock, independent of host clock skew.
                        $pdo->exec('UPDATE ce_forum_ai_admission_state SET sequence_no = sequence_no + 1 WHERE id = 1');
                        $seq=$pdo->query('SELECT sequence_no FROM ce_forum_ai_admission_state WHERE id = 1')->fetchColumn();
                        $pdo->prepare('INSERT INTO ce_forum_ai_admission (id,user_id,sequence_no,active,expires_at) VALUES (?,?,?,0,?)')->execute([$id,$userId,$seq,$now+self::WAIT_SECONDS+5]);
                    }
                    $active=(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active = 1')->fetchColumn();
                    if($active>=self::ACTIVE)return false;
                    $first=$pdo->query('SELECT id FROM ce_forum_ai_admission WHERE active = 0 ORDER BY sequence_no LIMIT 1')->fetchColumn();
                    if($first!==$id)return false;
                    $pdo->prepare('UPDATE ce_forum_ai_admission SET active = 1, expires_at = ? WHERE id = ?')->execute([$now+self::LEASE_SECONDS,$id]);
                    return true;
                });
                if($granted)return $id;
                if(connection_aborted())break;
                usleep(200000);
            }while(hrtime(true)<$deadline);
            throw new ForumException('AI занят. Повторите запрос позднее.',429);
        }catch(\Throwable $e){try{$this->release($id);}catch(\Throwable){}throw $e;}
    }

    public function release(string $id):void
    {
        $this->db->transaction(function(PDO $pdo)use($id):void{
            $this->lock($pdo);
            $pdo->prepare('DELETE FROM ce_forum_ai_admission WHERE id = ?')->execute([$id]);
        });
    }

    public function cooldown(int $seconds):void
    {
        $until=time()+max(1,min(120,$seconds));
        $this->db->transaction(function(PDO $pdo)use($until):void{
            $this->lock($pdo);
            $pdo->prepare('UPDATE ce_forum_ai_admission_state SET cooldown_until = CASE WHEN cooldown_until < ? THEN ? ELSE cooldown_until END WHERE id = 1')->execute([$until,$until]);
        });
    }

    public function cleanup():int
    {
        return $this->db->transaction(function(PDO $pdo):int{
            $this->lock($pdo);$q=$pdo->prepare('DELETE FROM ce_forum_ai_admission WHERE expires_at <= ?');$q->execute([time()]);return $q->rowCount();
        });
    }
}
