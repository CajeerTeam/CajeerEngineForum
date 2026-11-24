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

    /** Parse a bounded expression: words, quoted phrases and -excluded words/phrases. */
    public static function expression(string $term): array
    {
        preg_match_all('/((?<!\\S)-)?"([^"\\r\\n]+)"|((?<!\\S)-)?([\\p{L}\\p{N}]+)/u', $term, $matches, PREG_SET_ORDER);
        if (substr_count($term,'"')%2!==0 || count($matches)>16) return [];
        $out=[];
        foreach ($matches as $m) {
            $phrase=isset($m[2]) && $m[2]!=='';
            $words=self::words($phrase?$m[2]:($m[4]??''));
            // Preserve repeated words in phrases ("very very").
            if ($phrase) {preg_match_all('/[\\p{L}\\p{N}]+/u',$m[2],$w);$words=$w[0];}
            if (!$words) continue;
            $out[]=['text'=>implode(' ',$words),'negative'=>($phrase?($m[1]??''):($m[3]??''))==='-','phrase'=>$phrase];
        }
        return $out;
    }

    /** @return array{0:string,1:array} */
    public static function build(string $driver,string $term,int $offset,string $acl,?string $tag,string $mode,array $options=[]): array
    {
        $expr=self::expression($term);$positive=array_values(array_filter($expr,static fn($x)=>!$x['negative']));
        $negative=array_values(array_filter($expr,static fn($x)=>$x['negative']));
        $discover=$options['discover']??'';$trigram=$driver==='pgsql_trgm';if($trigram)$driver='pgsql';$sort=$options['sort']??'relevance';
        $empty='SELECT t.id,t.title,t.prefix,t.updated_at FROM ce_forum_live_threads t WHERE 1=0';
        if ($term!=='' && !$positive) return [$empty,[]];
        $limit=' LIMIT '.($discover!==''?8:25).' OFFSET '.($discover!==''?0:max(0,$offset));
        $ctes=[];$parts=[];$params=[];$cteParams=[];
        $query='';
        $phraseOnly=false;
        if ($term!=='' && $driver==='pgsql' && !$trigram) {
            // PostgreSQL language dictionaries omit stop words. Use indexed normal terms
            // (or one phrase as a candidate), then verify exact phrases/exclusions on source text.
            $indexed=array_values(array_filter($positive,static fn($x)=>!$x['phrase']));
            $phraseOnly=!$indexed;
            if ($phraseOnly) $indexed=[$positive[0]];
            $yes=[];
            foreach ($indexed as $x) {
                $lang=preg_match('/\\p{Cyrillic}/u',$x['text'])?'russian':'english';
                if ($x['phrase']) {
                    $piece="plainto_tsquery('$lang'::regconfig,?)";$cteParams[]=$x['text'];
                } elseif ($discover==='suggest') {
                    // Only tokenized letters/numbers enter to_tsquery; the prefix operator is server-owned.
                    $piece="(to_tsquery('$lang'::regconfig,?) || to_tsquery('simple'::regconfig,?))";array_push($cteParams,$x['text'].':*',$x['text'].':*');
                } else {
                    $piece="(plainto_tsquery('$lang'::regconfig,?) || plainto_tsquery('simple'::regconfig,?))";array_push($cteParams,$x['text'],$x['text']);
                }
                $yes[]=$piece;
            }
            $ctes[]='needle AS (SELECT ('.implode($discover==='similar'?' || ':' && ',$yes).') AS q)';
        } elseif ($term!=='' && $driver==='sqlite') {
            $yes=array_map(static fn($x)=>'"'.$x['text'].'"'.($discover==='suggest'?'*':''),$positive);
            $query='('.implode($discover==='similar'?' OR ':' AND ',$yes).')';
            foreach ($negative as $x) $query.=' NOT "'.$x['text'].'"';
        } elseif ($term!=='' && $driver==='mysql') {
            $query=implode(' ',array_map(static fn($x)=>($x['negative']?'-':($discover==='similar'?'':'+')).($x['phrase']?'"'.$x['text'].'"':$x['text']).($discover==='suggest'?'*':''),[...$positive,...$negative]));
        }
        $sources=$trigram?['thread']:($mode==='posts'?['post']:($term===''?['thread']:($discover!==''?['thread','tag']:['thread','tag','post'])));
        foreach ($sources as $kind) {
            [$table,$fts,$columns,$id,$post,$weight]=match($kind) {
                'thread'=>['ce_forum_threads','ce_forum_fts_threads','title,prefix','s.id','NULL',4],
                'tag'=>['ce_forum_thread_tags','ce_forum_fts_tags','tag','s.thread_id','NULL',2],
                'post'=>['ce_forum_posts','ce_forum_fts_posts','body','s.thread_id','s.id',1],
            };
            $scope=' JOIN ce_forum_live_threads t ON t.id='.$id;
            $author=$kind==='post'?'s.author_id':'t.author_id';
            $date=$mode==='posts'?'s.created_at':'t.created_at';
            // Title/tag/filter-only hits must describe an actually readable publication,
            // just like sitemap and SSR. Post hits already prove this themselves.
            $where=$acl.($kind==='post'?' AND s.deleted_at IS NULL':' AND EXISTS (SELECT 1 FROM ce_forum_posts published WHERE published.thread_id=t.id AND published.deleted_at IS NULL)');$filters=[];
            if ($tag!==null) {$where.=' AND EXISTS (SELECT 1 FROM ce_forum_thread_tags g WHERE g.thread_id=t.id AND g.tag=?)';$filters[]=$tag;}
            foreach (['board_id'=>'t.board_id','author_id'=>$mode==='posts'?'s.author_id':'t.author_id','from'=>$date,'to'=>$date] as $key=>$column) {
                if (!isset($options[$key])) continue;
                $where.=' AND '.$column.($key==='from'?'>=':($key==='to'?'<':'=')).'?';$filters[]=$options[$key];
            }
            $viewer=$options['viewer']??'';
            if ($viewer!=='') {
                foreach (array_unique([$author,'t.author_id']) as $a) {
                    $where.=" AND NOT EXISTS (SELECT 1 FROM ce_forum_user_blocks b WHERE (b.user_id=? AND b.blocked_id=$a) OR (b.blocked_id=? AND b.user_id=$a)) AND NOT EXISTS (SELECT 1 FROM ce_forum_member_relations i WHERE i.user_id=? AND i.target_id=$a AND i.kind='ignore')";
                    array_push($filters,$viewer,$viewer,$viewer);
                }
            }
            if (isset($options['author_id']) || $discover!=='') {
                // Discovery and author-filtered activity must respect profile/activity visibility.
                $a=$mode==='posts'?'s.author_id':'t.author_id';
                if (isset($options['author_id'])) $where.=" AND EXISTS (SELECT 1 FROM ce_users au WHERE au.id=$a AND au.status='active')";
                $visible="(COALESCE(v.profile_visibility,'public')='public' OR (COALESCE(v.profile_visibility,'public')='members' AND ?=1)) AND (COALESCE(v.activity_visibility,'public')='public' OR (COALESCE(v.activity_visibility,'public')='members' AND ?=1))";
                $where.=" AND ($a IS NULL OR NOT EXISTS (SELECT 1 FROM ce_forum_member_preferences v WHERE v.user_id=$a AND NOT ($visible))".($viewer!==''?" OR $a=?":"").')';
                array_push($filters,$viewer===''?0:1,$viewer===''?0:1);if($viewer!=='')$filters[]=$viewer;
            }
            $question=$options['question']??'all';
            if ($question!=='all') {
                $where.=' AND EXISTS (SELECT 1 FROM ce_forum_questions qu WHERE qu.thread_id=t.id'.match($question){'solved'=>' AND qu.accepted_post_id IS NOT NULL AND EXISTS (SELECT 1 FROM ce_forum_live_posts ap WHERE ap.id=qu.accepted_post_id AND ap.thread_id=t.id)','unsolved'=>' AND NOT EXISTS (SELECT 1 FROM ce_forum_live_posts ap WHERE ap.id=qu.accepted_post_id AND ap.thread_id=t.id)','unanswered'=>' AND NOT EXISTS (SELECT 1 FROM ce_forum_live_posts ap WHERE ap.thread_id=t.id AND ap.ordinal>1)',default=>''}.')';
            }
            if ($driver==='pgsql' && !$trigram) {
                $text=match($kind){'thread'=>"(s.title || ' ' || s.prefix)",'tag'=>'s.tag','post'=>'s.body'};
                foreach ([...$positive,...$negative] as $x) {
                    if (!$x['phrase'] && !$x['negative']) continue;
                    $where.=' AND '.$text.($x['negative']?' !~* ?':' ~* ?');
                    $filters[]='(^|[^[:alnum:]])'.str_replace(' ','[^[:alnum:]]+',$x['text']).'([^[:alnum:]]|$)';
                }
            }
            $postExpr=$kind==='post'?$post:($driver==='pgsql'?'CAST(NULL AS uuid)':'NULL');
            $tagExpr=$kind==='tag'?'s.tag':($driver==='pgsql'?'CAST(NULL AS text)':'NULL');
            $select="SELECT $id AS thread_id,$postExpr AS post_id,$tagExpr AS matched_tag,$weight AS weight,";
            if ($term==='') {
                $parts[]=$select."0.0 AS relevance,$date AS match_date FROM $table s$scope WHERE $where";
            } elseif ($trigram) {
                $live=$kind==='tag'?'':'s0.deleted_at IS NULL AND ';
                $sub="SELECT s0.*, similarity(title,?) AS relevance FROM $table s0 WHERE $live".'title % ?';
                array_push($params,$term,$term);
                $parts[]=$select."s.relevance,$date AS match_date FROM ($sub) s$scope WHERE $where";
            } elseif ($driver==='pgsql') {
                $vector='('.self::VECTORS[$kind].')';
                // Filtering physically deleted rows before ranking limits FTS work
                // without relaxing the later live-thread / ACL predicates.
                $live=$kind==='tag'?'':'s0.deleted_at IS NULL AND ';
                $sub="SELECT s0.*, ts_rank_cd($vector,needle.q) AS relevance FROM $table s0 CROSS JOIN needle WHERE $live".($phraseOnly?"($vector @@ needle.q OR numnode(needle.q)=0)":"$vector @@ needle.q");
                $parts[]=$select."s.relevance,$date AS match_date FROM ($sub) s$scope WHERE $where";
            } elseif ($driver==='sqlite') {
                $ctes[]="hit_$kind AS MATERIALIZED (SELECT rowid,-bm25($fts) AS relevance FROM $fts WHERE $fts MATCH ?)";$cteParams[]=$query;
                $keyJoin=$kind==='tag'?"s.thread_id=k.source_id AND s.tag=k.tag":"s.id=k.source_id";
                $parts[]=$select."h.relevance,$date AS match_date FROM hit_$kind h JOIN ce_forum_fts_keys k ON k.id=h.rowid AND k.kind='$kind' JOIN $table s ON $keyJoin$scope WHERE $where";
            } elseif ($driver==='mysql_literal') {
                $text=match($kind){'thread'=>"CONCAT(title,' ',prefix)",'tag'=>'tag','post'=>'body'};
                $yes=[];$no=[];$yesParams=[];$noParams=[];
                foreach ([...$positive,...$negative] as $x) {
                    $pattern='(^|[^[:alnum:]])'.str_replace(' ','[^[:alnum:]]+',$x['text']).($discover==='suggest'?'':'([^[:alnum:]]|$)');
                    if ($x['negative']) {$no[]="LOWER($text) NOT REGEXP ?";$noParams[]=mb_strtolower($pattern);} else {$yes[]="LOWER($text) REGEXP ?";$yesParams[]=mb_strtolower($pattern);}
                }
                $live=$kind==='tag'?'':'s0.deleted_at IS NULL AND ';
                $sub="SELECT s0.*, 1.0 AS relevance FROM $table s0 WHERE $live(".implode($discover==='similar'?' OR ':' AND ',$yes).')'.($no?' AND '.implode(' AND ',$no):'');
                array_push($params,...$yesParams,...$noParams);
                $parts[]=$select."s.relevance,$date AS match_date FROM ($sub) s$scope WHERE $where";
            } else {
                $match="MATCH($columns) AGAINST (? IN BOOLEAN MODE)";
                $live=$kind==='tag'?'':'s0.deleted_at IS NULL AND ';
                $sub="SELECT s0.*, $match AS relevance FROM $table s0 WHERE $live$match > 0";array_push($params,$query,$query);
                $parts[]=$select."s.relevance,$date AS match_date FROM ($sub) s$scope WHERE $where";
            }
            array_push($params,...$filters);
        }
        $ctes[]='candidates AS ('.implode(' UNION ALL ',$parts).')';
        if(count($sources)===1) $ctes[]='ranked AS (SELECT candidates.*, 1 AS pick FROM candidates)';
        else $ctes[]='ranked AS (SELECT candidates.*, ROW_NUMBER() OVER (PARTITION BY '.($mode==='posts'?'post_id':($discover==='suggest'?'thread_id,matched_tag':'thread_id')).' ORDER BY weight DESC,relevance DESC,post_id,matched_tag) AS pick FROM candidates)';
        $order=match($sort){'newest'=>'match_date DESC,','oldest'=>'match_date ASC,','active'=>'updated_at DESC,','replies'=>'reply_count DESC,',default=>''};
        $order.='weight DESC,relevance DESC,updated_at DESC,id,post_id,matched_tag';
        $replyCount='(SELECT COUNT(*) FROM ce_forum_live_posts rp WHERE rp.thread_id=t.id AND rp.ordinal>1)';
        $countInPage=$sort==='replies'?','.$replyCount.' AS reply_count':'';
        $countAfterPage=$sort==='replies'?'':',' . str_replace('t.id','r.id',$replyCount).' AS reply_count';
        $ctes[]='result_page AS (SELECT t.id,t.title,t.prefix,t.updated_at,r.post_id,r.matched_tag,r.weight,r.relevance,r.match_date,COUNT(*) OVER () AS total_results'.$countInPage.' FROM ranked r JOIN ce_forum_live_threads t ON t.id=r.thread_id WHERE r.pick=1 ORDER BY '.$order.$limit.')';
        $body='p.body';
        if ($driver==='pgsql' && $term!=='' && !$trigram) {
            $lang=preg_match('/\\p{Cyrillic}/u',$term)?'russian':'english';
            $body="CASE WHEN p.id IS NULL THEN NULL ELSE ts_headline('$lang'::regconfig,p.body,needle.q,'MaxWords=35, MinWords=12, MaxFragments=1, StartSel=__CE_FTS_START__, StopSel=__CE_FTS_END__') END";
        }
        $sql='WITH '.implode(',',$ctes).' SELECT r.*'.$countAfterPage.','.$body.' AS match_body, CASE WHEN p.id IS NULL THEN NULL ELSE 1+FLOOR((SELECT COUNT(*) FROM ce_forum_live_posts earlier WHERE earlier.thread_id=p.thread_id AND earlier.ordinal<p.ordinal)/25.0) END AS post_page FROM result_page r LEFT JOIN ce_forum_live_posts p ON p.id=r.post_id'.($driver==='pgsql' && $term!=='' && !$trigram?' CROSS JOIN needle':'').' ORDER BY '.preg_replace('/\\b(match_date|reply_count|weight|relevance|updated_at|id|post_id|matched_tag)\\b/','r.$1',$order);
        return [$sql,[...$cteParams,...$params]];
    }
}
