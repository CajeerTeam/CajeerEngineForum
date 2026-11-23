<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use PDO;

/**
 * Account- and content-backed engagement, rather than fabricated visitor metrics.
 * Only aggregate statistics leave this read model. IDs stay inside the snapshot.
 * Counts reflect retained forum posts (including the opening post of a thread),
 * not anonymous reads, login sessions, edited/deleted posts or private messages.
 */
final readonly class ForumEngagementAnalytics
{
    public const COHORT_DAYS = 90;
    public const FUNNEL_DAYS = 7;
    public function __construct(private DatabaseManager $db) {}

    public function get(int $days = 30, ?int $now = null): array
    {
        if (!in_array($days, [7,30,90], true)) {
            throw new ForumException('Период вовлечённости: 7, 30 или 90 дней.', 422);
        }
        $at = $now ?? time();
        $today = intdiv($at, 86400) * 86400;
        $oldest = $today - (self::COHORT_DAYS - 1) * 86400;
        $from = $today - ($days - 1) * 86400;
        // Exclude the incomplete current UTC day for cohort maturity, not for activity.
        $untilExclusive = $today + 86400;
        $previousWeek = $today - 13 * 86400;
        $currentWeek = $today - 6 * 86400;

        return $this->db->readSnapshot(function (PDO $pdo) use ($days,$today,$oldest,$from,$untilExclusive,$previousWeek,$currentWeek):array {
            // PostgreSQL TIMESTAMPTZ must always be rendered as UTC calendar dates.
            if ($this->db->driver()==='pgsql')$pdo->exec("SET LOCAL TIME ZONE 'UTC'");
            $cohorts = [];
            $memberCohort = [];
            for ($day=$oldest; $day<=$today; $day+=86400) {
                $cohorts[$day] = ['date'=>gmdate('Y-m-d',$day),'registrations'=>0,
                    'd1'=>0,'d7'=>0,'d30'=>0,'first_contribution'=>0,'second_contribution'=>0];
            }
            $membership = "EXISTS (SELECT 1 FROM ce_user_roles ur JOIN ce_roles r ON r.id=ur.role_id "
                ."WHERE ur.user_id=u.id AND r.handle IN ('forum-member','forum-moderator'))";
            $users = $pdo->prepare('SELECT u.id,u.created_at FROM ce_users u WHERE u.created_at>=? AND u.created_at<? AND '
                .$membership.' ORDER BY u.created_at,u.id');
            $users->execute([gmdate('Y-m-d H:i:s',$oldest),gmdate('Y-m-d H:i:s',$untilExclusive)]);
            while ($row=$users->fetch(PDO::FETCH_ASSOC)) {
                $registered = self::day((string)$row['created_at']);
                if ($registered===null || !isset($cohorts[$registered])) continue;
                $cohorts[$registered]['registrations']++;
                $memberCohort[(string)$row['id']] = $registered;
            }
            $users->closeCursor();

            $daily = [];
            for ($day=$from; $day<=$today; $day+=86400) {
                $daily[$day]=['date'=>gmdate('Y-m-d',$day),'contributors'=>0,'contributions'=>0,'topics'=>0,'replies'=>0];
            }
            $seen = []; $actors = [];
            // Each post is a single contribution. Opening posts have ordinal=1;
            // do NOT also count ce_forum_threads or the same post is doubled.
            // Streaming and ordered rows avoid fetching full post bodies and avoid
            // materializing one row per post. Per-user state supports exact distincts.
            $posts=$pdo->prepare('SELECT p.author_id,p.created_at,p.ordinal FROM ce_forum_live_posts p '
                .'JOIN ce_users u ON u.id=p.author_id WHERE p.created_at>=? AND p.created_at<? AND '
                .$membership.' ORDER BY p.created_at,p.id');
            $posts->execute([gmdate('Y-m-d H:i:s',$oldest),gmdate('Y-m-d H:i:s',$untilExclusive)]);
            while ($post=$posts->fetch(PDO::FETCH_ASSOC)) {
                $author=(string)$post['author_id'];$day=self::day((string)$post['created_at']);
                if ($day===null || $day<$oldest || $day>$today) continue;
                $actor=$actors[$author]??['last_day'=>null,'first_week'=>false,'second_week'=>false,
                    'previous_week'=>false,'current_week'=>false,'in_window'=>false];
                if ($actor['last_day']!==$day) {
                    if(isset($daily[$day])) $daily[$day]['contributors']++;
                    $actor['last_day']=$day;
                    $registered=$memberCohort[$author]??null;
                    if($registered!==null){
                        $delta=intdiv($day-$registered,86400);
                        $key=match($delta){1=>'d1',7=>'d7',30=>'d30',default=>null};
                        if($key!==null)$cohorts[$registered][$key]++;
                    }
                }
                if(isset($daily[$day])){
                    $daily[$day]['contributions']++;
                    $daily[$day][(int)$post['ordinal']===1?'topics':'replies']++;
                    $actor['in_window']=true;
                }
                if ($day>=$previousWeek && $day<$currentWeek)$actor['previous_week']=true;
                if ($day>=$currentWeek)$actor['current_week']=true;
                $registered=$memberCohort[$author]??null;
                if ($registered!==null && $day>=$registered && $day<$registered+self::FUNNEL_DAYS*86400){
                    if (!$actor['first_week']){
                        $actor['first_week']=true;
                        $cohorts[$registered]['first_contribution']++;
                    } elseif (!$actor['second_week']){
                        $actor['second_week']=true;
                        $cohorts[$registered]['second_contribution']++;
                    }
                }
                $actors[$author]=$actor;
            }
            $posts->closeCursor();

            $unique=0;$returning=0;$weekActive=0;$currentWeekActive=0;
            foreach($actors as $actor){
                if($actor['in_window'])$unique++;
                if($actor['current_week'])$currentWeekActive++;
                if($actor['previous_week'] && $actor['current_week'])$returning++;
                if($actor['current_week']||$actor['previous_week'])$weekActive++;
            }
            $totals=['contributions'=>0,'topics'=>0,'replies'=>0,'active_member_days'=>0];
            foreach($daily as $row){
                $totals['contributions']+=$row['contributions'];
                $totals['topics']+=$row['topics'];
                $totals['replies']+=$row['replies'];
                $totals['active_member_days']+=$row['contributors'];
            }
            $totals['contributors']=$unique;
            $totals['contributions_per_contributor']=$unique>0?round($totals['contributions']/$unique,2):null;
            $totals['current_week_contributors']=$currentWeekActive;
            $totals['returning_week_contributors']=$returning;
            $totals['previous_or_current_week_contributors']=$weekActive;

            $retention=[];
            foreach([1,7,30] as $offset){
                $eligible=0;$returned=0;
                foreach($cohorts as $start=>$row){
                    if($start+$offset*86400 >= $today)continue;
                    $eligible+=$row['registrations'];$returned+=$row['d'.$offset];
                }
                $retention['d'.$offset]=self::rate($eligible,$returned);
            }
            $funnel=['eligible_registrations'=>0,'first_contribution'=>0,'second_contribution'=>0,'within_days'=>self::FUNNEL_DAYS];
            foreach($cohorts as $start=>$row){
                if($start+self::FUNNEL_DAYS*86400>$today)continue;
                $funnel['eligible_registrations']+=$row['registrations'];
                $funnel['first_contribution']+=$row['first_contribution'];
                $funnel['second_contribution']+=$row['second_contribution'];
            }
            $funnel['first_rate']=self::ratio($funnel['first_contribution'],$funnel['eligible_registrations']);
            $funnel['second_rate']=self::ratio($funnel['second_contribution'],$funnel['eligible_registrations']);
            $funnel['repeat_rate']=self::ratio($funnel['second_contribution'],$funnel['first_contribution']);
            $publicCohorts=[];
            foreach($cohorts as $start=>$row){
                $row['d1_rate']=$start+86400<$today?self::ratio($row['d1'],$row['registrations']):null;
                $row['d7_rate']=$start+7*86400<$today?self::ratio($row['d7'],$row['registrations']):null;
                $row['d30_rate']=$start+30*86400<$today?self::ratio($row['d30'],$row['registrations']):null;
                $publicCohorts[]=$row;
            }
            return ['timezone'=>'UTC','from'=>gmdate('Y-m-d',$from),'until'=>gmdate('Y-m-d',$today),
                'cohort_from'=>gmdate('Y-m-d',$oldest),'days'=>$days,'summary'=>$totals,
                'daily'=>array_values($daily),'retention'=>$retention,'funnel'=>$funnel,
                'cohorts'=>array_reverse($publicCohorts),
                'method'=>'registered_forum_members_and_retained_authored_posts',
                'limitations'=>'Activity means authored forum posts, not unique visits or sessions. '
                    .'Deleted posts/threads, imported dates, removed roles and incomplete current UTC day affect results.'];
        });
    }

    private static function rate(int $eligible,int $returned):array
    {return ['eligible'=>$eligible,'returned'=>$returned,'percent'=>self::ratio($returned,$eligible)];}
    private static function ratio(int $numerator,int $denominator):?float
    {return $denominator>0?round($numerator*100/$denominator,1):null;}
    private static function day(string $timestamp):?int
    {
        // Every supported driver stores YYYY-MM-DD-prefixed timestamps. Parse
        // calendar days, not the server's local timezone or Unix time of a user.
        $part=substr($timestamp,0,10);
        if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$part))return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$part,new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d')===$part?$date->getTimestamp():null;
    }
}
