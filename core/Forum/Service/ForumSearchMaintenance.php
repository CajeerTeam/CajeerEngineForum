<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\Repository\ForumSearchQuery;
use PDO;

/** Transactional index validation and repair. Run rebuild in a write-free maintenance window. */
final readonly class ForumSearchMaintenance
{
    private const SOURCES = [
        'thread'=>['ce_forum_threads','ce_forum_fts_threads','id','title,prefix'],
        'post'=>['ce_forum_posts','ce_forum_fts_posts','id','body'],
        'tag'=>['ce_forum_thread_tags','ce_forum_fts_tags','thread_id','tag'],
    ];

    public function __construct(private DatabaseManager $db) {}

    public function check(): array
    {
        $driver=$this->db->driver();
        // SQLite FTS integrity-check is a write statement; use a writer transaction.
        return $driver==='sqlite'
            ? $this->db->transaction(fn(PDO $pdo)=>$this->checkIndexes($pdo,$driver))
            : $this->db->readSnapshot(fn(PDO $pdo)=>$this->checkIndexes($pdo,$driver));
    }

    private function checkIndexes(PDO $pdo,string $driver): array
    {
        $checks=[];
        foreach(self::SOURCES as $kind=>[$table,$index,$id,$columns]) {
            if($driver==='sqlite') {
                $pdo->exec("INSERT INTO $index($index) VALUES('integrity-check')");
                $join="k.kind='$kind' AND k.source_id=s.$id".($kind==='tag'?' AND k.tag=s.tag':'');
                $differences=implode(' OR ',array_map(static fn($c)=>"f.$c IS NOT s.$c",explode(',',$columns)));
                $missing=(int)$pdo->query("SELECT COUNT(*) FROM $table s LEFT JOIN ce_forum_fts_keys k ON $join LEFT JOIN $index f ON f.rowid=k.id WHERE f.rowid IS NULL OR $differences")->fetchColumn();
                $extra=(int)$pdo->query("SELECT COUNT(*) FROM $index f LEFT JOIN ce_forum_fts_keys k ON k.id=f.rowid LEFT JOIN $table s ON $join WHERE s.$id IS NULL")->fetchColumn();
                $keys=(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_fts_keys WHERE kind='$kind'")->fetchColumn();
                $rows=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                $orphan=(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_fts_keys k LEFT JOIN $table s ON s.$id=k.source_id".($kind==='tag'?' AND s.tag=k.tag':'')." WHERE k.kind='$kind' AND s.$id IS NULL")->fetchColumn();
                $names=array_map(static fn($suffix)=>$index.'_'.$suffix,['ai','ad','au']);
                $triggers=$pdo->query("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND name IN ('".implode("','",$names)."')")->fetchAll(PDO::FETCH_KEY_PAIR);
                $missingTriggers=array_values(array_diff($names,array_keys($triggers)));
                $invalidTriggers=[];
                foreach(['ai','ad','au'] as $suffix){
                    $trigger=$index.'_'.$suffix;$definition=(string)($triggers[$trigger]??'');
                    if($definition==='')continue;
                    $required=match($suffix){
                        'ai'=>['INSERT INTO '.$index,'INSERT INTO ce_forum_fts_keys'],
                        'ad'=>['DELETE FROM '.$index,'DELETE FROM ce_forum_fts_keys'],
                        'au'=>['DELETE FROM '.$index,'UPDATE ce_forum_fts_keys','INSERT INTO '.$index],
                    };
                    foreach($required as $fragment){
                        if(stripos($definition,$fragment)===false){$invalidTriggers[]=$trigger;break;}
                    }
                }
                $checks[$kind]=['ok'=>$missing===0&&$extra===0&&$orphan===0&&$keys===$rows&&$missingTriggers===[]&&$invalidTriggers===[],
                    'missing_or_stale'=>$missing,'extra'=>$extra,'orphan_keys'=>$orphan,
                    'source_rows'=>$rows,'keys'=>$keys,'missing_triggers'=>$missingTriggers,'invalid_triggers'=>$invalidTriggers];
            } elseif($driver==='pgsql') {
                $q=$pdo->prepare("SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_am a ON a.oid=c.relam WHERE c.oid=to_regclass(?) AND i.indrelid=to_regclass(?) AND i.indisvalid AND i.indisready AND i.indislive AND i.indpred IS NULL AND i.indexprs IS NOT NULL AND i.indnkeyatts=1 AND a.amname='gin'");
                $q->execute([$index,$table]);$definition=$q->fetchColumn();
                // Merely finding *some* GIN expression is insufficient: check both
                // dictionaries and all indexed source columns, not an unrelated GIN index.
                $valid=is_string($definition)&&str_contains($definition,"'english'")&&str_contains($definition,"'russian'")
                    &&str_contains($definition,'to_tsvector');
                foreach(explode(',',$columns) as $column)$valid=$valid&&str_contains((string)$definition,$column);
                $checks[$kind]=['ok'=>$valid,'definition'=>$definition===false?null:$definition];
            } else {
                $q=$pdo->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? AND index_type='FULLTEXT' ORDER BY seq_in_index");
                $q->execute([$table,$index]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);
                $checks[$kind]=['ok'=>$actual===explode(',',$columns),'columns'=>$actual];
            }
        }
        return ['ok'=>!in_array(false,array_column($checks,'ok'),true),'driver'=>$driver,'indexes'=>$checks];
    }

    /** Repair drift only if it exists, avoiding unnecessary blocking native DDL. */
    public function repair(): array
    {
        $before=$this->check();
        if($before['ok'])return $before+['repaired'=>false];
        return $this->rebuild()+['repaired'=>true];
    }

    /** Recreate indexes and SQLite synchronization triggers from source tables. */
    public function rebuild(): array
    {
        $driver=$this->db->driver();$pdo=$this->db->connection();
        if($driver!=='sqlite' && $pdo->inTransaction())throw new \LogicException('Native FTS rebuild cannot run inside a caller transaction');
        if($driver==='sqlite') {
            $this->db->transaction(function(PDO $pdo):void {
                foreach(self::SOURCES as $kind=>[$table,$index,$id,$columns]) {
                    // All source writes wait for this transaction. Rebuild cannot expose
                    // a half-indexed state, even if a statement or integrity check fails.
                    foreach(['ai','ad','au'] as $suffix)$pdo->exec('DROP TRIGGER IF EXISTS '.$index.'_'.$suffix);
                    $pdo->exec("DELETE FROM $index");
                    $pdo->exec("DELETE FROM ce_forum_fts_keys WHERE kind='$kind'");
                    $tag=$kind==='tag'?'tag':"''";
                    $pdo->exec("INSERT INTO ce_forum_fts_keys(kind,source_id,tag) SELECT '$kind',$id,$tag FROM $table");
                    $join="k.kind='$kind' AND k.source_id=s.$id".($kind==='tag'?' AND k.tag=s.tag':'');
                    $fields=implode(',',array_map(static fn($c)=>'s.'.$c,explode(',',$columns)));
                    $pdo->exec("INSERT INTO $index(rowid,$columns) SELECT k.id,$fields FROM $table s JOIN ce_forum_fts_keys k ON $join");
                    $this->createSqliteTriggers($pdo,$kind,$table,$index,$id,$columns);
                }
                $result=$this->checkIndexes($pdo,'sqlite');
                if(!$result['ok'])throw new \RuntimeException('Forum FTS consistency check failed: rebuild rolled back');
            });
        } elseif($driver==='pgsql') {
            // Executed during scheduled write-free maintenance. Native expression
            // indexes then stay current automatically on all future source writes.
            $this->db->transaction(function(PDO $pdo):void {
                foreach(self::SOURCES as $kind=>[$table,$index]) {
                    $pdo->exec('DROP INDEX IF EXISTS '.$index);
                    $pdo->exec('CREATE INDEX '.$index.' ON '.$table.' USING GIN (('.ForumSearchQuery::VECTORS[$kind].'))');
                }
            });
        } else {
            // InnoDB FTS is maintained automatically. MySQL ALTER TABLE causes
            // implicit commits; never pretend that rebuilding all three is atomic.
            foreach(self::SOURCES as [$table,$index,,$columns]) {
                $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
                $q->execute([$table,$index]);$exists=(int)$q->fetchColumn()>0;
                $pdo->exec('ALTER TABLE '.$table.' '.($exists?'DROP INDEX '.$index.', ':'').'ADD FULLTEXT INDEX '.$index.' ('.$columns.')');
            }
        }
        $result=$this->check();
        if(!$result['ok'])throw new \RuntimeException('Forum FTS rebuild did not restore consistency');
        return $result;
    }

    /** Restore SQLite trigger definitions as well as their data; missing triggers are not fixed by FTS backfill alone. */
    private function createSqliteTriggers(PDO $pdo,string $kind,string $table,string $index,string $id,string $columns):void
    {
        $key=static fn(string $prefix)=>$prefix.'.'.$id;
        $tag=static fn(string $prefix)=>$kind==='tag'?$prefix.'.tag':"''";
        $newKey=$key('new');$oldKey=$key('old');$newTag=$tag('new');$oldTag=$tag('old');
        $newFields=implode(',',array_map(static fn($c)=>'new.'.$c,explode(',',$columns)));
        $oldWhere="kind='$kind' AND source_id=$oldKey AND tag=$oldTag";
        $newWhere="kind='$kind' AND source_id=$newKey AND tag=$newTag";
        $pdo->exec("CREATE TRIGGER {$index}_ai AFTER INSERT ON $table BEGIN
            INSERT INTO ce_forum_fts_keys(kind,source_id,tag) VALUES('$kind',$newKey,$newTag);
            INSERT INTO $index(rowid,$columns) SELECT id,$newFields FROM ce_forum_fts_keys WHERE $newWhere;
        END");
        $pdo->exec("CREATE TRIGGER {$index}_ad AFTER DELETE ON $table BEGIN
            DELETE FROM $index WHERE rowid=(SELECT id FROM ce_forum_fts_keys WHERE $oldWhere);
            DELETE FROM ce_forum_fts_keys WHERE $oldWhere;
        END");
        $checks=[];
        foreach(array_unique([...explode(',',$columns),$id]) as $c)$checks[]="old.$c IS NOT new.$c";
        if($kind==='tag')$checks[]='old.tag IS NOT new.tag';
        $pdo->exec("CREATE TRIGGER {$index}_au AFTER UPDATE OF ".implode(',',array_unique([...explode(',',$columns),$id]))." ON $table
            WHEN ".implode(' OR ',$checks)." BEGIN
            DELETE FROM $index WHERE rowid=(SELECT id FROM ce_forum_fts_keys WHERE $oldWhere);
            UPDATE ce_forum_fts_keys SET source_id=$newKey,tag=$newTag WHERE $oldWhere;
            INSERT INTO $index(rowid,$columns) SELECT id,$newFields FROM ce_forum_fts_keys WHERE $newWhere;
        END");
    }
}
