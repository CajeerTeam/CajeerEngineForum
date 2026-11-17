<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Read-only operational report. Never expose statement text or user content. */
final readonly class ForumPerformanceCheck
{
    private const INDEXES=['ce_forum_live_latest_idx','ce_forum_live_board_idx','ce_forum_live_author_idx','ce_forum_live_ordinal_idx','ce_forum_live_sequence_idx','ce_forum_unread_board_idx','ce_forum_answer_delivery_idx'];
    public function __construct(private DatabaseManager $db) {}

    public function check(bool $analyze=false): array
    {
        $driver=$this->db->driver();
        return $this->db->readSnapshot(function(PDO $pdo)use($driver,$analyze){
            $indexNames=match($driver){
                'pgsql'=>$pdo->query('SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND i.indisvalid AND i.indisready')->fetchAll(PDO::FETCH_COLUMN),
                'sqlite'=>$pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN),
                default=>$pdo->query('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN),
            };
            $missing=array_values(array_diff(self::INDEXES,$indexNames));
            $result=['ok'=>$missing===[],'driver'=>$driver,'missing_indexes'=>$missing];
            if($missing!==[])return $result;
            $query='SELECT id FROM ce_forum_live_threads ORDER BY updated_at DESC,id LIMIT 25';
            if($driver==='pgsql'){
                $result['plan']=json_decode((string)$pdo->query('EXPLAIN ('.($analyze?'ANALYZE, BUFFERS, ':'').'FORMAT JSON) '.$query)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
                $result['tables']=$pdo->query("SELECT relname,n_live_tup,n_dead_tup,seq_scan,idx_scan,last_autovacuum,last_autoanalyze,pg_total_relation_size(relid) AS bytes FROM pg_stat_user_tables WHERE schemaname=current_schema() AND relname IN ('ce_forum_threads','ce_forum_posts','ce_forum_read_state','ce_forum_cache_generation_shards','ce_forum_notifications','ce_forum_answer_notifications') ORDER BY relname")->fetchAll(PDO::FETCH_ASSOC);
                $result['indexes']=$pdo->query("SELECT indexrelname,idx_scan,idx_tup_read,pg_relation_size(indexrelid) AS bytes FROM pg_stat_user_indexes WHERE schemaname=current_schema() AND indexrelname IN ('ce_forum_live_latest_idx','ce_forum_live_board_idx','ce_forum_live_author_idx','ce_forum_live_ordinal_idx','ce_forum_live_sequence_idx','ce_forum_unread_board_idx','ce_forum_answer_delivery_idx') ORDER BY indexrelname")->fetchAll(PDO::FETCH_ASSOC);
                $result['pg_stat_statements_available']=(int)$pdo->query("SELECT COUNT(*) FROM pg_extension WHERE extname='pg_stat_statements'")->fetchColumn()===1;
            }else{
                $result['plan']=$pdo->query(($driver==='sqlite'?'EXPLAIN QUERY PLAN ':'EXPLAIN ').$query)->fetchAll(PDO::FETCH_ASSOC);
            }
            $result['analyze']=$driver==='pgsql'&&$analyze;
            $result['cache_generation_count']=(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_cache_generation_effective')->fetchColumn();
            $result['ok']=$result['cache_generation_count']===27;
            return $result;
        });
    }
}
