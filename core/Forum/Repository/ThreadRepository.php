<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ThreadRepository extends Repository
{
    public function pageForPost(string $thread,string $post): int
    {return 1+intdiv((int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=? AND ordinal<(SELECT ordinal FROM ce_forum_live_posts WHERE id=? AND thread_id=?)',[$thread,$post,$thread])->fetchColumn(),25);}
    public function find(string $id): ?array { return $this->row('SELECT t.*,b.name AS board_name FROM ce_forum_live_threads t JOIN ce_forum_boards b ON b.id=t.board_id WHERE t.id=?', [$id]); }
    public function lockThread(string $id): ?array { return $this->lock('ce_forum_threads', 'id=? AND deleted_at IS NULL', [$id]); }
    public function lockForPost(string $post): ?array {
        $thread=$this->lock('ce_forum_threads','deleted_at IS NULL AND id=(SELECT thread_id FROM ce_forum_posts WHERE id=? AND deleted_at IS NULL)',[$post]);
        if ($thread===null) return null;
        $current=(new PostRepository($this->db))->findForWrite($post);
        if ($current===null) return null;
        if ($thread===null || $current['thread_id']!==$thread['id']) throw new \CajeerEngine\Forum\ForumException('Сообщение перемещено. Повторите запрос.',409);
        return $thread;
    }
    /** Match thread -> board -> report lock order, including SQLite's first write. */
    public function lockForReport(string $report): ?array
    {
        $thread=$this->lock('ce_forum_threads','id=(SELECT p.thread_id FROM ce_forum_posts p JOIN ce_forum_reports r ON r.post_id=p.id WHERE r.id=?)',[$report]);
        $current=$this->row('SELECT p.thread_id FROM ce_forum_posts p JOIN ce_forum_reports r ON r.post_id=p.id WHERE r.id=?',[$report]);
        if ($current===null) return null;
        if ($thread===null || $current['thread_id']!==$thread['id']) throw new \CajeerEngine\Forum\ForumException('Сообщение перемещено. Повторите запрос.',409);
        return $thread;
    }
    private const AUTHOR = "CASE WHEN u.status='active' AND (COALESCE(pref.profile_visibility,'public')='public' OR (pref.profile_visibility='members' AND ?<>'') OR u.id=?) THEN COALESCE(NULLIF(m.display_name,''),u.name) ELSE u.name END AS author";
    private const AUTHOR_JOIN = ' LEFT JOIN ce_users u ON u.id=t.author_id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id ';
    public function countInBoard(string $id): int { return (int)$this->query('SELECT COUNT(*) FROM ce_forum_live_threads WHERE board_id=?',[$id])->fetchColumn(); }
    public function inBoard(string $id, int $offset,string $viewer=''): array
    { return $this->rows('SELECT t.*,'.self::AUTHOR.',(SELECT COUNT(*) FROM ce_forum_live_posts p WHERE p.thread_id=t.id) AS posts FROM ce_forum_live_threads t'.self::AUTHOR_JOIN.'WHERE t.board_id=? ORDER BY t.pinned DESC,t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset,[$viewer,$viewer===''?null:$viewer,$id]); }
    public function latest(int $offset,array $allowed,string $viewer=''): array
    { return $this->rows('SELECT t.id,t.title,t.prefix,t.updated_at,t.pinned,t.locked,'.self::AUTHOR.' FROM ce_forum_live_threads t'.self::AUTHOR_JOIN.'WHERE '.$this->boardFilter('t.board_id',$allowed).' ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset,[$viewer,$viewer===''?null:$viewer]); }
    public function byAuthor(string $id, int $offset, array $allowed): array { return $this->rows('SELECT id,title,prefix,updated_at FROM ce_forum_live_threads WHERE author_id=? AND '.$this->boardFilter('board_id',$allowed).' ORDER BY updated_at DESC,id LIMIT 25 OFFSET '.$offset, [$id]); }
    public function search(string $term,int $offset,array $allowed,?string $tag=null,string $mode='threads',array $options=[]): array
    {
        if ($allowed===[]) return [];
        $driver=$this->db->driver();$acl=$this->boardFilter('t.board_id',$allowed);
        $fetch=function(string $engine,int $start) use ($term,$acl,$tag,$mode,$options): array {
            [$sql,$params]=ForumSearchQuery::build($engine,$term,$start,$acl,$tag,$mode,$options);
            return $this->rows($sql,$params);
        };
        // Short words cannot reliably be matched by MySQL's default FULLTEXT parser.
        // Native MySQL drops stop words; exact phrases and exclusions need literal verification.
        $short=$driver==='mysql' && (array_filter(ForumSearchQuery::words($term),static fn($word)=>mb_strlen($word)<3) || array_filter(ForumSearchQuery::expression($term),static fn($x)=>$x['phrase']||$x['negative']));
        $rows=$fetch($short?'mysql_literal':$driver,$offset);
        if ($driver==='mysql' && !$short && $term!=='' && !$rows && ($offset===0 || !$fetch($driver,0))) {
            $rows=$fetch('mysql_literal',$offset);
        }
        // Optional pg_trgm is never required to install or upgrade. Only discovery uses fuzzy matches.
        if (!$rows && $driver==='pgsql' && ($options['discover']??'')==='similar' && $term!=='' && (int)$this->query("SELECT CASE WHEN to_regprocedure('similarity(text,text)') IS NOT NULL AND to_regoperator('%(text,text)') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn()===1) {
            $rows=$fetch('pgsql_trgm',0);
        }
        foreach ($rows as &$row) {
            if (!array_key_exists('match_body',$row)) continue;
            $body=$row['match_body'];
            $row['match_type']=match((int)$row['weight']){4=>'title',2=>'tag',default=>'post'};
            if ($body!==null && $this->db->driver()==='pgsql') $body=str_replace(['__CE_FTS_START__','__CE_FTS_END__'],'',$body);unset($row['match_body'],$row['weight'],$row['relevance']);
            $row['post_page']=$row['post_page']===null?null:(int)$row['post_page'];
            $row['snippet']=$body===null?null:self::searchSnippet($body,$term);
            $row['total_results']=(int)$row['total_results'];$row['reply_count']=(int)$row['reply_count'];
        }
        unset($row);
        return $rows;
    }

    private static function searchSnippet(string $body,string $term): string
    {
        $body=trim(preg_replace('/\s+/u',' ',$body)??$body);
        $position=null;
        foreach (ForumSearchQuery::words($term) as $word) {
            $found=mb_stripos($body,$word);
            if ($found!==false && ($position===null || $found<$position)) $position=$found;
        }
        $start=max(0,($position??0)-70);
        return ($start>0?'…':'').mb_substr($body,$start,240).(mb_strlen($body)>$start+240?'…':'');
    }
    public function insert(string $id, string $board, string $actor, string $title): void { $this->query('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)', [$id,$board,$actor,$title]); }
    public function touch(string $id): void { $this->query('UPDATE ce_forum_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?', [$id]); }
    public function moderate(string $id, string $action): void
    {
        $sql = match ($action) {
            'lock' => 'UPDATE ce_forum_threads SET locked=1 WHERE id=?',
            'unlock' => 'UPDATE ce_forum_threads SET locked=0 WHERE id=?',
            'pin' => 'UPDATE ce_forum_threads SET pinned=1 WHERE id=?',
            'unpin' => 'UPDATE ce_forum_threads SET pinned=0 WHERE id=?',
            'purge' => 'DELETE FROM ce_forum_threads WHERE id=?',
            default => throw new \LogicException('Unknown moderation action'),
        };
        $this->query($sql, [$id]);
    }
}
