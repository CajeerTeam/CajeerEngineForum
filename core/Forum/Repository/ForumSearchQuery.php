<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

/** Driver-specific indexed candidate generation. Only the server supplies the ACL predicate. */
final class ForumSearchQuery
{
    public const VECTORS = [
        'thread'=>"to_tsvector('english'::regconfig,title || ' ' || prefix) || to_tsvector('russian'::regconfig,title || ' ' || prefix)",
        'post'=>"to_tsvector('english'::regconfig,body) || to_tsvector('russian'::regconfig,body)",
        'tag'=>"to_tsvector('english'::regconfig,tag) || to_tsvector('russian'::regconfig,tag)",
    ];

    /** Literal words only: no user-provided SQL/FTS operators, prefixes or arbitrary expressions. */
    public static function words(string $term): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $term, $matches);
        return array_values(array_unique($matches[0]??[]));
    }

    /** @return array{0:string,1:array} */
    public static function build(string $driver,string $term,int $offset,string $acl,?string $tag,string $mode): array
    {
        $words=self::words($term);
        $params=[];
        $tagFilter=$tag===null?'':' AND EXISTS (SELECT 1 FROM ce_forum_thread_tags g WHERE g.thread_id=t.id AND g.tag=?)';
        $limit=' LIMIT 25 OFFSET '.max(0,$offset);
        if ($term==='') {
            return ['SELECT t.id,t.title,t.prefix,t.updated_at FROM ce_forum_live_threads t WHERE '.$acl.$tagFilter.' ORDER BY t.updated_at DESC,t.id'.$limit,$tag===null?[]:[$tag]];
        }
        if (!$words) return ['SELECT t.id,t.title,t.prefix,t.updated_at FROM ce_forum_live_threads t WHERE 1=0',[]];
        $ctes=[];$parts=[];
        if ($driver==='pgsql') {
            $queries=[];
            foreach ($words as $word) {
                $lang=preg_match('/\p{Cyrillic}/u',$word)?'russian':'english';
                $queries[]="(plainto_tsquery('$lang'::regconfig,?) || plainto_tsquery('simple'::regconfig,?))";array_push($params,$word,$word);
            }
            $ctes[]='needle AS (SELECT '.implode(' && ',$queries).' AS q)';
        }
        $sources=$mode==='posts'?['post']:['thread','tag','post'];
        foreach ($sources as $kind) {
            [$table,$fts,$columns,$id,$post,$weight]=match($kind) {
                'thread'=>['ce_forum_threads','ce_forum_fts_threads','title,prefix','s.id','NULL',4],
                'tag'=>['ce_forum_thread_tags','ce_forum_fts_tags','tag','s.thread_id','NULL',2],
                'post'=>['ce_forum_posts','ce_forum_fts_posts','body','s.thread_id','s.id',1],
            };
            // The live thread join and live post predicate apply before ranking or pagination.
            $scope=' JOIN ce_forum_live_threads t ON t.id='.$id;
            $where=$acl.$tagFilter.($kind==='post'?' AND s.deleted_at IS NULL':'');
            if ($driver==='pgsql') {
                $vector='('.self::VECTORS[$kind].')';
                // Unqualified expression columns are isolated in the indexed subquery.
                $select="SELECT s0.*, ts_rank_cd($vector,needle.q) AS relevance FROM $table s0 CROSS JOIN needle WHERE $vector @@ needle.q";
                $parts[]="SELECT $id AS thread_id,".($kind==='post'?$post:'CAST(NULL AS uuid)')." AS post_id,$weight AS weight,s.relevance FROM ($select) s$scope WHERE $where";
            } elseif ($driver==='sqlite') {
                $query=implode(' AND ',array_map(static fn($w)=>'"'.$w.'"',$words));
                // Materialization keeps FTS5 auxiliary functions in their MATCH cursor context.
                $ctes[]="hit_$kind AS MATERIALIZED (SELECT rowid,-bm25($fts) AS relevance FROM $fts WHERE $fts MATCH ?)";
                // CTE placeholders occur before the candidate SELECT placeholders.
                $keyJoin=$kind==='tag'?"s.thread_id=k.source_id AND s.tag=k.tag":"s.id=k.source_id";
                $parts[]="SELECT $id AS thread_id,$post AS post_id,$weight AS weight,h.relevance FROM hit_$kind h JOIN ce_forum_fts_keys k ON k.id=h.rowid AND k.kind='$kind' JOIN $table s ON $keyJoin$scope WHERE $where";
            } elseif ($driver==='mysql_literal') {
                // InnoDB ignores short/stop words and can score ubiquitous words zero.
                // Use literal whole-word matches only when native search cannot serve the query.
                $text=match($kind){'thread'=>"CONCAT(title,' ',prefix)",'tag'=>'tag','post'=>'body'};
                $predicates=[];
                foreach ($words as $word) {$predicates[]="LOWER($text) REGEXP ?";$params[]='(^|[^[:alnum:]])'.mb_strtolower($word).'([^[:alnum:]]|$)';}
                $select="SELECT s0.*, 1.0 AS relevance FROM $table s0 WHERE ".implode(' AND ',$predicates);
                $parts[]="SELECT $id AS thread_id,$post AS post_id,$weight AS weight,s.relevance FROM ($select) s$scope WHERE $where";
            } else {
                $query=implode(' ',array_map(static fn($w)=>'+'.$w,$words));
                $match="MATCH($columns) AGAINST (? IN BOOLEAN MODE)";
                $select="SELECT s0.*, $match AS relevance FROM $table s0 WHERE $match > 0";
                $parts[]="SELECT $id AS thread_id,$post AS post_id,$weight AS weight,s.relevance FROM ($select) s$scope WHERE $where";
                array_push($params,$query,$query);
            }
            if ($tag!==null && $driver!=='sqlite') $params[]=$tag;
        }
        if ($driver==='sqlite') {
            $params=array_fill(0,count($sources),$query);
            if ($tag!==null) array_push($params,...array_fill(0,count($sources),$tag));
        }
        $ctes[]='candidates AS ('.implode(' UNION ALL ',$parts).')';
        $ctes[]='ranked AS (SELECT candidates.*, ROW_NUMBER() OVER (PARTITION BY '.($mode==='posts'?'post_id':'thread_id').' ORDER BY weight DESC,relevance DESC,post_id) AS pick FROM candidates)';
        $ctes[]='result_page AS (SELECT t.id,t.title,t.prefix,t.updated_at,r.post_id,r.weight,r.relevance FROM ranked r JOIN ce_forum_live_threads t ON t.id=r.thread_id WHERE r.pick=1 ORDER BY r.weight DESC,r.relevance DESC,t.updated_at DESC,t.id,r.post_id'.$limit.')';
        // Only the selected page reads the full body; PostgreSQL creates a matching fragment using its lexemes.
        $body='p.body';
        if ($driver==='pgsql') {
            $lang=preg_match('/\p{Cyrillic}/u',$term)?'russian':'english';
            $body="CASE WHEN p.id IS NULL THEN NULL ELSE ts_headline('$lang'::regconfig,p.body,needle.q,'MaxWords=35, MinWords=12, MaxFragments=1, StartSel=__CE_FTS_START__, StopSel=__CE_FTS_END__') END";
        }
        $sql='WITH '.implode(',',$ctes).' SELECT r.id,r.title,r.prefix,r.updated_at,r.post_id,r.weight,r.relevance,'.$body.' AS match_body, CASE WHEN p.id IS NULL THEN NULL ELSE 1+FLOOR((SELECT COUNT(*) FROM ce_forum_live_posts earlier WHERE earlier.thread_id=p.thread_id AND earlier.ordinal<p.ordinal)/25.0) END AS post_page FROM result_page r LEFT JOIN ce_forum_live_posts p ON p.id=r.post_id'.($driver==='pgsql'?' CROSS JOIN needle':'').' ORDER BY r.weight DESC,r.relevance DESC,r.updated_at DESC,r.id,r.post_id';
        return [$sql,$params];
    }
}
