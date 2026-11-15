<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;

/** Operations for the forum's database-owned indexes, independent of CMS search. */
final readonly class ForumSearchMaintenance
{
    private const SOURCES=[
        'thread'=>['ce_forum_threads','ce_forum_fts_threads','id','title,prefix'],
        'post'=>['ce_forum_posts','ce_forum_fts_posts','id','body'],
        'tag'=>['ce_forum_thread_tags','ce_forum_fts_tags','thread_id','tag'],
    ];
    public function __construct(private DatabaseManager $db) {}

    public function check(): array
    {
        $pdo=$this->db->connection();$driver=$this->db->driver();$checks=[];
        foreach (self::SOURCES as $kind=>[$table,$index,$id,$columns]) {
            if ($driver==='sqlite') {
                $pdo->exec("INSERT INTO $index($index) VALUES('integrity-check')");
                $join="k.kind='$kind' AND k.source_id=s.$id".($kind==='tag'?' AND k.tag=s.tag':'');
                $differences=implode(' OR ',array_map(static fn($c)=>"f.$c<>s.$c",explode(',',$columns)));
                $missing=(int)$pdo->query("SELECT COUNT(*) FROM $table s LEFT JOIN ce_forum_fts_keys k ON $join LEFT JOIN $index f ON f.rowid=k.id WHERE f.rowid IS NULL OR $differences")->fetchColumn();
                $extra=(int)$pdo->query("SELECT COUNT(*) FROM $index f LEFT JOIN ce_forum_fts_keys k ON k.id=f.rowid LEFT JOIN $table s ON $join WHERE s.$id IS NULL")->fetchColumn();
                $keys=(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_fts_keys WHERE kind='$kind'")->fetchColumn();
                $rows=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                $checks[$kind]=['ok'=>$missing===0&&$extra===0&&$keys===$rows,'missing_or_stale'=>$missing,'extra'=>$extra,'source_rows'=>$rows,'keys'=>$keys];
            } elseif ($driver==='pgsql') {
                $q=$pdo->prepare('SELECT COUNT(*) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_am a ON a.oid=c.relam WHERE c.oid=to_regclass(?) AND i.indisvalid AND i.indisready AND a.am=\'gin\'');$q->execute([$index]);$checks[$kind]=['ok'=>(int)$q->fetchColumn()===1];
            } else {
                $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? AND index_type='FULLTEXT'");$q->execute([$table,$index]);$checks[$kind]=['ok'=>(int)$q->fetchColumn()===count(explode(',',$columns))];
            }
        }
        return ['ok'=>!in_array(false,array_column($checks,'ok'),true),'driver'=>$driver,'indexes'=>$checks];
    }

    /** Run during a write-free maintenance window; MySQL rebuilds may take table locks. */
    public function rebuild(): array
    {
        $driver=$this->db->driver();$pdo=$this->db->connection();
        if ($driver==='sqlite') $this->db->transaction(function()use($pdo){
            foreach (self::SOURCES as $kind=>[$table,$index,$id,$columns]) {
                $pdo->exec("DELETE FROM $index");$pdo->exec("DELETE FROM ce_forum_fts_keys WHERE kind='$kind'");
                $tag=$kind==='tag'?'tag':"''";
                $pdo->exec("INSERT INTO ce_forum_fts_keys(kind,source_id,tag) SELECT '$kind',$id,$tag FROM $table");
                $join="k.kind='$kind' AND k.source_id=s.$id".($kind==='tag'?' AND k.tag=s.tag':'');
                $fields=implode(',',array_map(static fn($c)=>'s.'.$c,explode(',',$columns)));
                $pdo->exec("INSERT INTO $index(rowid,$columns) SELECT k.id,$fields FROM $table s JOIN ce_forum_fts_keys k ON $join");
            }
        });
        else foreach (self::SOURCES as [$table,$index]) {
            if ($driver==='pgsql') $pdo->exec('REINDEX INDEX '.$index);
            else $pdo->exec('ALTER TABLE '.$table.' FORCE');
        }
        return $this->check();
    }
}
