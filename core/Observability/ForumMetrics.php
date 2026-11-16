<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Service\ForumRuntimeCache;
use PDO;

/** Bounded, read-only forum samples. No identifiers, payloads or SQL bindings leave this class. */
final class ForumMetrics
{
    public const LIMIT = 10001;
    /** Fixed predicates only. Counts saturate at LIMIT; due ages exclude future scheduled work. */
    public const QUEUES = [
        'notifications'=>['ce_forum_notifications','state=0','created_at',true],
        'answer_notifications'=>['ce_forum_answer_notifications','state=0','created_at',true],
        'mail'=>['ce_forum_mail_delivery',"state='pending'",'due_at',false],
        'extensions'=>['ce_forum_extension_events',"state='pending'",'due_at',false],
        'media'=>['ce_forum_media_state',"state='pending'",'due_at',false],
        'replicas'=>['ce_forum_media_replicas',"state='pending'",'due_at',false],
        'moderation'=>['ce_forum_review_submissions',"status='pending'",'created_at',false],
        'reports'=>['ce_forum_reports',"status='open'",'created_at',true],
        'extension_dead'=>['ce_forum_extension_events',"state='dead'",null,false],
        'mail_dead'=>['ce_forum_mail_delivery',"state='dead'",null,false],
        'media_failed'=>['ce_forum_media_state',"state='failed'",null,false],
        'imports'=>['ce_forum_import_jobs',"state='running'",'updated_at',false],
    ];
    public static function queries(): array
    {
        $queries=[];
        foreach(self::QUEUES as $name=>[$table,$where,$column]){
            $queries[$name]=['count'=>"SELECT COUNT(*) FROM (SELECT 1 FROM $table WHERE $where LIMIT ".self::LIMIT.') AS bounded'];
            if($column!==null){
                $predicate=$where.($column==='due_at'?" AND $column>0":'');
                $queries[$name]['oldest']="SELECT $column FROM $table WHERE $predicate ORDER BY $column LIMIT 1";
            }
        }
        return $queries;
    }
    public static function collect(PDO $pdo,string $driver,int $now): array
    {
        $queues=[];$deadline=hrtime(true)+4_000_000_000;
        foreach(self::queries() as $name=>$sql){
            if(hrtime(true)>$deadline)throw new \RuntimeException('Forum telemetry budget exceeded');
            $count=(int)$pdo->query($sql['count'])->fetchColumn();
            $entry=['count'=>$count,'capped'=>$count>=self::LIMIT];
            if(isset($sql['oldest'])){
                $raw=$pdo->query($sql['oldest'])->fetchColumn();
                // SQL timestamps in the forum schema are UTC; BIGINT fields are epoch seconds.
                $ts=$raw===false||$raw===null?null:(self::QUEUES[$name][3]?(new \DateTimeImmutable((string)$raw,new \DateTimeZone('UTC')))->getTimestamp():(int)$raw);
                // Zero is a sentinel for immediately due legacy media, not 1970 backlog.
                $entry['oldest_age_seconds']=$ts!==null&&$ts!==false&&$ts>0?max(0,$now-$ts):0;
            }
            $queues[$name]=$entry;
        }
        $pg=[];
        if($driver==='pgsql'){
            $row=$pdo->query("SELECT count(*) AS connections, count(*) FILTER (WHERE wait_event_type='Lock') AS lock_waiters, current_setting('max_connections')::int AS max_connections FROM pg_stat_activity WHERE backend_type='client backend'")->fetch(PDO::FETCH_ASSOC);
            foreach(['connections','lock_waiters','max_connections'] as $key)$pg[$key]=(int)$row[$key];
        }
        return ['ok'=>true,'sampled_at'=>$now,'queues'=>$queues,'postgres'=>$pg];
    }
    public static function database(DatabaseManager $db): array
    {
        $driver=$db->driver();
        return $db->readSnapshot(static function(PDO $pdo)use($driver){
            if($driver==='pgsql'){$pdo->exec("SET LOCAL statement_timeout = '1500ms'");$pdo->exec("SET LOCAL lock_timeout = '500ms'");}
            return self::collect($pdo,$driver,time());
        });
    }
    public static function cache(): array
    {
        if(!ForumRuntimeCache::enabled())return ['ok'=>true,'enabled'=>false];
        $d=ForumRuntimeCache::diagnostics();
        if(!($d['connected']??false))return ['ok'=>false,'enabled'=>true];
        $stats=[];foreach(['hit','miss','coalesced'] as $key)$stats[$key]=max(0,(int)($d['stats'][$key]??0));
        return ['ok'=>true,'enabled'=>true,'stats'=>$stats,'used_memory_bytes'=>(int)$d['used_memory_bytes'],'maxmemory_bytes'=>(int)$d['maxmemory_bytes']];
    }
    public static function prometheus(array $checks): string
    {
        $out=[];$g=static function(string $name,int $value)use(&$out){$out[]='# TYPE '.$name.' gauge';$out[]=$name.' '.$value;};
        $d=$checks['forum_database']??[];
        if(($d['ok']??false)&&isset($d['queues'])){
            $g('cajeer_forum_sample_timestamp_seconds',(int)$d['sampled_at']);
            foreach(['count'=>'queue_items','capped'=>'queue_count_capped','oldest_age_seconds'=>'queue_oldest_age_seconds'] as $field=>$metric){
                $name='cajeer_forum_'.$metric;$out[]='# TYPE '.$name.' gauge';
                foreach(self::QUEUES as $queue=>$_)if(isset($d['queues'][$queue][$field]))$out[]=$name.'{queue="'.$queue.'"} '.(int)$d['queues'][$queue][$field];
            }
            foreach(['connections','lock_waiters','max_connections'] as $key)if(isset($d['postgres'][$key]))$g('cajeer_forum_postgres_'.$key,(int)$d['postgres'][$key]);
        }
        $c=$checks['forum_cache']??[];
        if(($c['ok']??false)&&($c['enabled']??false)&&isset($c['stats'])){
            $out[]='# TYPE cajeer_forum_cache_requests_total counter';foreach(['hit','miss','coalesced'] as $key)$out[]='cajeer_forum_cache_requests_total{result="'.$key.'"} '.(int)$c['stats'][$key];
            foreach(['used_memory_bytes','maxmemory_bytes'] as $key)$g('cajeer_forum_redis_'.$key,(int)$c[$key]);
        }
        $r=$checks['cold_recovery']??[];
        if(isset($r['restore_seconds'])){
            $g('cajeer_forum_recovery_duration_seconds',(int)$r['restore_seconds']);
            $g('cajeer_forum_recovery_snapshot_age_seconds',(int)$r['snapshot_age_seconds']);
            $g('cajeer_forum_recovery_objectives_met',(int)$r['ok']);
        }
        return $out?implode("\n",$out)."\n":'';
    }
}
