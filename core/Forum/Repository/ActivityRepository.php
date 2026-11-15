<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

/** Read-time aggregates: no stale score or private-content counters to invalidate. */
final readonly class ActivityRepository extends Repository
{
    public const TYPES=['like'=>1,'helpful'=>2,'celebrate'=>1];
    private const SCORE="CASE WHEN r.reaction_type='helpful' THEN 2 ELSE 1 END";
    private const PUBLIC_PROFILE="COALESCE(pref.profile_visibility,'public')='public' AND COALESCE(pref.activity_visibility,'public')='public'";
    public function stats(string $id,array $publicBoards): array
    {
        $filter=$this->boardFilter('t.board_id',$publicBoards);
        $p=$this->row('SELECT COUNT(*) AS posts FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.author_id=? AND '.$filter,[$id]);
        $t=$this->row('SELECT COUNT(*) AS threads FROM ce_forum_live_threads t WHERE t.author_id=? AND '.$filter,[$id]);
        $r=$this->row('SELECT COUNT(*) AS reactions,COALESCE(SUM('.self::SCORE.'),0) AS reputation FROM ce_forum_reactions r JOIN ce_forum_live_posts p ON p.id=r.post_id JOIN ce_forum_live_threads t ON t.id=p.thread_id JOIN ce_users u ON u.id=r.user_id AND u.status=\'active\' WHERE p.author_id=? AND r.user_id<>p.author_id AND '.$filter,[$id]);
        return ['posts'=>(int)$p['posts'],'threads'=>(int)$t['threads'],'reactions'=>(int)$r['reactions'],'reputation'=>(int)$r['reputation']];
    }
    public function leaderboard(array $publicBoards,int $offset): array
    {
        $sql="SELECT u.id,COALESCE(NULLIF(m.display_name,''),u.name) AS name,SUM(".self::SCORE.") AS reputation,COUNT(*) AS reactions FROM ce_forum_reactions r JOIN ce_forum_live_posts p ON p.id=r.post_id JOIN ce_forum_live_threads t ON t.id=p.thread_id JOIN ce_users u ON u.id=p.author_id AND u.status='active' JOIN ce_users voter ON voter.id=r.user_id AND voter.status='active' LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id WHERE r.user_id<>p.author_id AND ".self::PUBLIC_PROFILE.' AND '.$this->boardFilter('t.board_id',$publicBoards).' GROUP BY u.id,u.name,m.display_name ORDER BY reputation DESC,u.id LIMIT 25 OFFSET '.$offset;
        return $this->rows($sql);
    }
    public function feed(string $viewer,string $scope,array $boards,int $offset): array
    {
        $uid=$viewer===''?null:$viewer;
        // SQL applies ACL, profile/activity visibility and relationship filters BEFORE pagination.
        $sql="SELECT p.id AS post_id,p.thread_id,p.ordinal,p.created_at,t.title,t.board_id,u.id AS author_id,COALESCE(NULLIF(m.display_name,''),u.name) AS author FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id JOIN ce_users u ON u.id=p.author_id AND u.status='active' LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id WHERE ".$this->boardFilter('t.board_id',$boards)." AND (u.id=? OR ((COALESCE(pref.profile_visibility,'public')='public' OR (pref.profile_visibility='members' AND ?=1)) AND (COALESCE(pref.activity_visibility,'public')='public' OR (pref.activity_visibility='members' AND ?=1))))";
        $params=[$uid,$viewer===''?0:1,$viewer===''?0:1];
        if($scope==='mine'){$sql.=' AND p.author_id=?';$params[]=$uid;}
        if($scope==='following'){$sql.=" AND EXISTS (SELECT 1 FROM ce_forum_member_relations f WHERE f.user_id=? AND f.target_id=p.author_id AND f.kind='follow')";$params[]=$uid;}
        if($viewer!==''){$sql.=" AND NOT EXISTS (SELECT 1 FROM ce_forum_member_relations i WHERE i.user_id=? AND i.target_id=p.author_id AND i.kind='ignore')";$params[]=$uid;}
        return $this->rows($sql.' ORDER BY p.created_at DESC,p.id DESC LIMIT 25 OFFSET '.$offset,$params);
    }
    public function relations(string $user,string $kind,int $offset): array
    {
        return $this->rows("SELECT r.target_id AS id,CASE WHEN u.status='active' AND COALESCE(pref.profile_visibility,'public') IN ('public','members') THEN COALESCE(NULLIF(m.display_name,''),u.name) ELSE NULL END AS name FROM ce_forum_member_relations r JOIN ce_users u ON u.id=r.target_id LEFT JOIN ce_forum_member_preferences pref ON pref.user_id=u.id LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id WHERE r.user_id=? AND r.kind=? ORDER BY r.target_id LIMIT 25 OFFSET ".$offset,[$user,$kind]);
    }
    public function relation(string $user,string $target,string $kind): bool {return $this->row('SELECT kind FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind=?',[$user,$target,$kind])!==null;}
    public function setRelation(string $user,string $target,string $kind,bool $enabled): void
    {
        if(!$enabled){$this->query('DELETE FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind=?',[$user,$target,$kind]);return;}
        $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE kind=kind':' ON CONFLICT (user_id,target_id,kind) DO NOTHING';
        $this->query('INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,?)'.$suffix,[$user,$target,$kind]);
    }
    public function rules(): array {return $this->rows('SELECT code,threshold,enabled FROM ce_forum_achievement_rules ORDER BY code');}
    public function saveRule(string $code,int $threshold,bool $enabled): void {$this->query('UPDATE ce_forum_achievement_rules SET threshold=?,enabled=? WHERE code=?',[$threshold,(int)$enabled,$code]);}
}
