<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use PDO;

/** Incremental privacy maintenance, without deleting security decision history. */
final readonly class SecurityMaintenance
{
    public function __construct(private string $root, private ?DatabaseManager $database=null) {}

    public function run(): array
    {
        $removed=(new RateLimiter($this->root))->cleanup();
        $db=$this->database ?? new DatabaseManager(new ConfigRepository($this->root));
        $days=max(1,min(90,$db->config()->int('security.audit_network_days',30)));
        $cutoff=gmdate('Y-m-d H:i:s',time()-$days*86400);
        $updated=$db->transaction(function(PDO $pdo)use($cutoff){
            // Select a bounded batch, then recheck cutoff at update. Same SQL for all drivers.
            $q=$pdo->prepare('SELECT id FROM ce_audit_log WHERE created_at < ? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL) ORDER BY id LIMIT 500');
            $q->execute([$cutoff]); $ids=$q->fetchAll(PDO::FETCH_COLUMN); $q->closeCursor();
            if (!$ids) return 0;
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$pdo->prepare('UPDATE ce_audit_log SET ip_address=NULL,user_agent=NULL WHERE id IN ('.$marks.') AND created_at < ?');
            $q->execute([...$ids,$cutoff]); return $q->rowCount();
        });
        return ['ok'=>true,'expired_counters_removed'=>$removed,'audit_network_rows_minimized'=>$updated];
    }
}
