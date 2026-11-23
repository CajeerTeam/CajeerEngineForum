<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use PDO;

/** ACP-only, anonymous daily analytics; no personal IDs or event payloads.
 * Uses the same consistent snapshot for applied rollups and still pending raw
 * events, including an optional full-length previous-period comparison.
 */
final readonly class ForumAnalyticsDashboard
{
    public function __construct(private DatabaseManager $db) {}

    public function get(?string $from=null, ?string $until=null, ?int $now=null): array
    {
        $at=$now??time();
        $today=intdiv($at,86400)*86400;
        $oldest=$today-(ForumAnalyticsRollup::DAILY_DAYS-1)*86400;
        if ($from===null && $until===null) {
            $start=$today-29*86400;
            $last=$today;
        } elseif ($from!==null && $until!==null) {
            $start=self::day($from);
            $last=self::day($until);
        } else {
            throw new ForumException('Укажите обе даты периода.',422);
        }
        if ($start<$oldest || $last>$today || $start>$last || $last-$start>=ForumAnalyticsRollup::DAILY_DAYS*86400) {
            throw new ForumException('Период должен быть от 1 до 365 дней в пределах сохранённых дневных агрегатов (UTC).',422);
        }
        $days=intdiv($last-$start,86400)+1;
        $previousStart=$start-$days*86400;
        $compare=$previousStart >= $oldest;
        $queryStart=$compare?$previousStart:$start;
        $end=$last+86400;

        return $this->db->readSnapshot(function(PDO $pdo) use ($start,$last,$days,$compare,$previousStart,$queryStart,$end):array {
            $grouped=[];
            // Both streams are additive only for distinct ledger states. Reading
            // them in one repeatable snapshot prevents double counting a batch.
            $stmt=$pdo->prepare('SELECT day,event_name,total FROM ce_forum_analytics_daily WHERE day>=? AND day<?');
            $stmt->execute([$queryStart,$end]);
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)) self::merge($grouped,$row);
            $stmt->closeCursor();
            $stmt=$pdo->prepare('SELECT created_at-(created_at % 86400) AS day,event_name,COUNT(*) AS total '
                .'FROM ce_forum_analytics_events WHERE aggregated_at IS NULL AND created_at>=? AND created_at<? '
                .'GROUP BY event_name,created_at-(created_at % 86400)');
            $stmt->execute([$queryStart,$end]);
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)) self::merge($grouped,$row);
            $stmt->closeCursor();

            $daily=[];$totals=[];$prior=[];
            $views=0;$actions=0;$priorViews=0;$priorActions=0;
            for($day=$start;$day<=$last;$day+=86400){
                $events=$grouped[$day]??[];ksort($events,SORT_STRING);
                $dayViews=0;$dayActions=0;
                foreach($events as $name=>$count){
                    $totals[$name]=($totals[$name]??0)+$count;
                    if(in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true))$dayViews+=$count;
                    else $dayActions+=$count;
                }
                $views+=$dayViews;$actions+=$dayActions;
                $daily[]=['day'=>gmdate('Y-m-d',$day),'views'=>$dayViews,'actions'=>$dayActions,
                    'total'=>$dayViews+$dayActions,'events'=>$events];
            }
            if($compare){
                for($day=$previousStart;$day<$start;$day+=86400){
                    foreach($grouped[$day]??[] as $name=>$count){
                        $prior[$name]=($prior[$name]??0)+$count;
                        if(in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true))$priorViews+=$count;
                        else $priorActions+=$count;
                    }
                }
            }
            ksort($totals,SORT_STRING);
            $breakdown=[];
            foreach(array_unique(array_merge(array_keys($totals),array_keys($prior))) as $name){
                $n=$totals[$name]??0;$was=$prior[$name]??0;
                $breakdown[]=['event_name'=>$name,'category'=>in_array($name,ForumNativeAnalytics::PAGE_EVENTS,true)?'view':'action',
                    'total'=>$n,'previous'=>$compare?$was:null,'difference'=>$compare?$n-$was:null,
                    'percent_change'=>$compare?self::percent($n,$was):null];
            }
            usort($breakdown,static fn(array $a,array $b):int=>$b['total']<=>$a['total'] ?: strcmp($a['event_name'],$b['event_name']));
            $state=$pdo->query('SELECT last_aggregated_at,last_recalculated_at FROM ce_forum_analytics_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            $pending=(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_analytics_events WHERE aggregated_at IS NULL')->fetchColumn();
            return [
                'timezone'=>'UTC','from'=>gmdate('Y-m-d',$start),'until'=>gmdate('Y-m-d',$last),'days'=>$days,
                'summary'=>['page_views'=>$views,'actions'=>$actions,'total'=>$views+$actions,
                    'previous_page_views'=>$compare?$priorViews:null,'previous_actions'=>$compare?$priorActions:null,
                    'previous_total'=>$compare?$priorViews+$priorActions:null,
                    'page_views_change_pct'=>$compare?self::percent($views,$priorViews):null,
                    'actions_change_pct'=>$compare?self::percent($actions,$priorActions):null,
                    'total_change_pct'=>$compare?self::percent($views+$actions,$priorViews+$priorActions):null],
                'comparison'=>['available'=>$compare,'from'=>$compare?gmdate('Y-m-d',$previousStart):null,
                    'until'=>$compare?gmdate('Y-m-d',$start-86400):null],
                'daily'=>$daily,'totals'=>$totals,'breakdown'=>$breakdown,
                'pending_events'=>$pending,'raw_retention_days'=>ForumAnalyticsRollup::RAW_DAYS,
                'aggregate_retention_days'=>ForumAnalyticsRollup::DAILY_DAYS,
                'last_aggregated_at'=>(int)($state['last_aggregated_at']??0),
                'last_recalculated_at'=>(int)($state['last_recalculated_at']??0),
            ];
        });
    }

    private static function merge(array &$groups,array $row):void
    {
        $day=(int)$row['day'];$name=(string)$row['event_name'];$n=(int)$row['total'];
        if ($n<0)throw new \RuntimeException('Invalid analytics counter');
        $groups[$day][$name]=($groups[$day][$name]??0)+$n;
    }

    private static function day(string $value):int
    {
        if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value))throw new ForumException('Неверный формат даты UTC.',422);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new ForumException('Несуществующая дата UTC.',422);
        return $date->getTimestamp();
    }

    private static function percent(int $current,int $prior):?float
    { return $prior===0?null:round(($current-$prior)*100/$prior,1); }
}
