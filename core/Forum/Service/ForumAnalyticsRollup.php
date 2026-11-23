<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use PDO;

/** Durable first-party UTC daily counters. The raw event is marked as applied in
 * the SAME database transaction as the increment: retries and worker crashes
 * cannot double-count or lose a committed batch.
 */
final readonly class ForumAnalyticsRollup
{
    public const RAW_DAYS = 90;
    public const DAILY_DAYS = 365;
    public const BATCH_SIZE = 500;

    public function __construct(private DatabaseManager $db) {}

    /** Serialize aggregator/rebuild/retention across all workers and SQL drivers. */
    private static function lock(PDO $pdo): void
    {
        $updated = $pdo->exec('UPDATE ce_forum_analytics_state SET lock_version=lock_version+1 WHERE id=1');
        if ($updated !== 1) throw new \RuntimeException('Forum analytics migration 0082 is missing');
    }

    private function increment(PDO $pdo, int $day, string $name, int $total, int $now): void
    {
        if ($this->db->driver() === 'mysql') {
            $sql='INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES (?,?,?,?) '
                .'ON DUPLICATE KEY UPDATE total=total+VALUES(total),updated_at=VALUES(updated_at)';
        } else {
            $sql='INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES (?,?,?,?) '
                .'ON CONFLICT(day,event_name) DO UPDATE SET total=ce_forum_analytics_daily.total+excluded.total,updated_at=excluded.updated_at';
        }
        $pdo->prepare($sql)->execute([$day,$name,$total,$now]);
    }

    /** Atomically aggregate at most $limit unapplied events. Returns actual applied count. */
    public function aggregateBatch(int $limit=self::BATCH_SIZE, ?int $now=null): int
    {
        if ($limit<1 || $limit>1000) throw new \InvalidArgumentException('Invalid analytics batch');
        $at=$now??time();
        return $this->db->transaction(function(PDO $pdo)use($limit,$at):int{
            self::lock($pdo);
            $stmt=$pdo->query('SELECT id,event_name,created_at FROM ce_forum_analytics_events WHERE aggregated_at IS NULL '
                .'ORDER BY created_at,id LIMIT '.(int)$limit);
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$stmt->closeCursor();
            if (!$rows) return 0;
            $groups=[];$ids=[];
            foreach($rows as $row){
                $day=intdiv((int)$row['created_at'],86400)*86400;
                $name=(string)$row['event_name'];
                $key=$day.':'.$name;
                if (!isset($groups[$key])) $groups[$key]=[$day,$name,0];
                $groups[$key][2]++;
                $ids[]=$row['id'];
            }
            foreach($groups as [$day,$name,$total]) $this->increment($pdo,$day,$name,$total,$at);
            $sql='UPDATE ce_forum_analytics_events SET aggregated_at=? WHERE aggregated_at IS NULL AND id IN ('
                .implode(',',array_fill(0,count($ids),'?')).')';
            $update=$pdo->prepare($sql);$update->execute([$at,...$ids]);
            if ($update->rowCount()!==count($ids)) throw new \RuntimeException('Forum analytics ledger changed during aggregation');
            $pdo->prepare('UPDATE ce_forum_analytics_state SET last_aggregated_at=? WHERE id=1')->execute([$at]);
            return count($ids);
        });
    }

    /** Rebuild an inclusive UTC date range from retained, ALREADY APPLIED raw
     * entries. New, unapplied events remain visible in reports and will be
     * added by the next batch. This avoids races with concurrent page writes.
     */
    public function recalculate(string $from, string $until, ?int $now=null): array
    {
        $start=self::parseDay($from);$last=self::parseDay($until);
        $at=$now??time();$today=intdiv($at,86400)*86400;
        $earliest=intdiv($at-self::RAW_DAYS*86400,86400)*86400+86400;
        if ($start<$earliest || $last>$today || $last<$start || $last-$start>30*86400)
            throw new ForumException('Пересчёт доступен только за 1–31 день в пределах полного срока хранения исходных событий.',422);
        $end=$last+86400;
        return $this->db->transaction(function(PDO $pdo)use($start,$end,$at):array{
            self::lock($pdo);
            $stmt=$pdo->prepare('SELECT event_name,created_at-(created_at % 86400) AS day,COUNT(*) AS total '
                .'FROM ce_forum_analytics_events WHERE aggregated_at IS NOT NULL AND created_at>=? AND created_at<? '
                .'GROUP BY event_name,created_at-(created_at % 86400) ORDER BY day,event_name');
            $stmt->execute([$start,$end]);$groups=$stmt->fetchAll(PDO::FETCH_ASSOC);$stmt->closeCursor();
            $pdo->prepare('DELETE FROM ce_forum_analytics_daily WHERE day>=? AND day<?')->execute([$start,$end]);
            $insert=$pdo->prepare('INSERT INTO ce_forum_analytics_daily(day,event_name,total,updated_at) VALUES (?,?,?,?)');
            $total=0;
            foreach($groups as $g){$count=(int)$g['total'];$total+=$count;
                $insert->execute([(int)$g['day'],(string)$g['event_name'],$count,$at]);}
            $pdo->prepare('UPDATE ce_forum_analytics_state SET last_recalculated_at=? WHERE id=1')->execute([$at]);
            return ['from'=>gmdate('Y-m-d',$start),'until'=>gmdate('Y-m-d',$end-86400),
                'days'=>intdiv($end-$start,86400),'rows'=>count($groups),'events'=>$total];
        });
    }

    private static function parseDay(string $value): int
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value)) throw new ForumException('Неверный формат даты UTC.',422);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$value) throw new ForumException('Несуществующая дата UTC.',422);
        return $date->getTimestamp();
    }

    /** Prune ONLY processed raw events. A worker outage can never destroy
     * unapplied counts. Aggregate rows outlive raw events for a full year.
     */
    public function cleanup(?int $now=null): array
    {
        $at=$now??time();$cutoff=$at-self::RAW_DAYS*86400;
        $dailyCutoff=intdiv($at,86400)*86400-(self::DAILY_DAYS-1)*86400;
        return $this->db->transaction(function(PDO $pdo)use($cutoff,$dailyCutoff):array{
            self::lock($pdo);
            $s=$pdo->prepare('SELECT id FROM ce_forum_analytics_events WHERE aggregated_at IS NOT NULL AND created_at<? ORDER BY created_at,id LIMIT 500');
            $s->execute([$cutoff]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();
            $deleted=0;
            if ($ids){
                $sql='DELETE FROM ce_forum_analytics_events WHERE aggregated_at IS NOT NULL AND created_at<? AND id IN ('
                    .implode(',',array_fill(0,count($ids),'?')).')';
                $s=$pdo->prepare($sql);$s->execute([$cutoff,...$ids]);$deleted=$s->rowCount();
            }
            $s=$pdo->prepare('DELETE FROM ce_forum_analytics_daily WHERE day<?');
            $s->execute([$dailyCutoff]);
            return ['raw_deleted'=>$deleted,'daily_deleted'=>$s->rowCount()];
        });
    }

    /** One consistent DB snapshot prevents counting an in-flight batch twice
     * (once in the rollup and once as a pending event).
     */
    public function report(int $days, ?int $now=null): array
    {
        if (!in_array($days,[7,30,90,365],true)) throw new \InvalidArgumentException('Invalid analytics period');
        $at=$now??time();$start=intdiv($at,86400)*86400-($days-1)*86400;$end=$at+1;
        return $this->db->readSnapshot(function(PDO $pdo)use($days,$at,$start,$end):array{
            $totals=[];$series=[];
            for($i=0;$i<$days;$i++){$day=$start+$i*86400;$series[$day]=['day'=>gmdate('Y-m-d',$day),'views'=>0,'actions'=>0];}
            $s=$pdo->prepare('SELECT day,event_name,total FROM ce_forum_analytics_daily WHERE day>=? AND day<? ORDER BY day,event_name');
            $s->execute([$start,$end]);
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
            $s=$pdo->prepare('SELECT created_at-(created_at % 86400) AS day,event_name,COUNT(*) AS total '
                .'FROM ce_forum_analytics_events WHERE aggregated_at IS NULL AND created_at>=? AND created_at<? '
                .'GROUP BY event_name,created_at-(created_at % 86400)');
            $s->execute([$start,$end]);$rows=array_merge($rows,$s->fetchAll(PDO::FETCH_ASSOC));$s->closeCursor();
            foreach($rows as $row){
                $day=(int)$row['day'];if(!isset($series[$day]))continue;
                $name=(string)$row['event_name'];$n=(int)$row['total'];
                $totals[$name]=($totals[$name]??0)+$n;
                $key=in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true)?'views':'actions';
                $series[$day][$key]+=$n;
            }
            ksort($totals,SORT_STRING);
            $state=$pdo->query('SELECT last_aggregated_at,last_recalculated_at FROM ce_forum_analytics_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            $pending=(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events WHERE aggregated_at IS NULL')->fetchColumn();
            return ['days'=>$days,'timezone'=>'UTC','from'=>gmdate('Y-m-d',$start),'until'=>gmdate('Y-m-d',$at),
                'totals'=>$totals,'page_views'=>array_sum(array_column($series,'views')),'actions'=>array_sum(array_column($series,'actions')),
                'series'=>array_values($series),'retention_days'=>self::RAW_DAYS,'raw_retention_days'=>self::RAW_DAYS,
                'aggregate_retention_days'=>self::DAILY_DAYS,'pending_events'=>$pending,
                'last_aggregated_at'=>(int)($state['last_aggregated_at']??0),
                'last_recalculated_at'=>(int)($state['last_recalculated_at']??0)];
        });
    }
}
