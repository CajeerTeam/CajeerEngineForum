<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ProfileRepository extends Repository
{
    public const PROFILE = ['display_name'=>'','bio'=>'','location'=>'','website'=>'','signature'=>''];
    public const PREFERENCES = ['profile_visibility'=>'public','activity_visibility'=>'public','show_groups'=>0,'show_signatures'=>1,'theme'=>'system','locale'=>'ru-RU','timezone'=>'UTC'];

    public function user(string $id): ?array { return $this->row("SELECT id,name,created_at FROM ce_users WHERE id=? AND status='active'",[$id]); }
    public function lockUser(string $id): ?array { return $this->lock('ce_users','id=?',[$id]); }
    public function lockFields(): void { if(!$this->lock('ce_forum_tree_lock','id=1',[]))throw new \RuntimeException('Forum tree lock missing'); }
    public function profile(string $id): array { return array_intersect_key($this->row('SELECT * FROM ce_forum_member_profiles WHERE user_id=?',[$id])??[],self::PROFILE)+self::PROFILE; }
    public function preferences(string $id): array { return array_intersect_key($this->row('SELECT * FROM ce_forum_member_preferences WHERE user_id=?',[$id])??[],self::PREFERENCES)+self::PREFERENCES; }
    public function save(string $id,string $kind,array $data): void
    {
        // Caller locks the user, so insert/update is portable and race-free.
        $table=match($kind){'profile'=>'ce_forum_member_profiles','preferences'=>'ce_forum_member_preferences'};
        $keys=array_keys($data);
        if ($this->row("SELECT user_id FROM $table WHERE user_id=?",[$id])) {
            $this->query("UPDATE $table SET ".implode(',',array_map(fn($k)=>$k.'=?',$keys)).' WHERE user_id=?',[...array_values($data),$id]);
        } else $this->query("INSERT INTO $table (user_id,".implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys)+1,'?')).')',[$id,...array_values($data)]);
    }
    public function fields(): array { return $this->rows('SELECT id,label,visibility,position FROM ce_forum_profile_fields ORDER BY position,id'); }
    public function values(string $id): array { return $this->rows('SELECT f.id,f.label,f.visibility,v.field_value FROM ce_forum_profile_fields f JOIN ce_forum_profile_field_values v ON v.field_id=f.id WHERE v.user_id=? ORDER BY f.position,f.id',[$id]); }
    public function saveValues(string $id,array $values): void
    {
        foreach($values as $field=>$value){
            $this->query('DELETE FROM ce_forum_profile_field_values WHERE user_id=? AND field_id=?',[$id,$field]);
            if($value!=='')$this->query('INSERT INTO ce_forum_profile_field_values(user_id,field_id,field_value) VALUES(?,?,?)',[$id,$field,$value]);
        }
    }
    public function saveField(string $id,string $label,string $visibility,int $position): void
    {
        if($this->row('SELECT id FROM ce_forum_profile_fields WHERE id=?',[$id]))$this->query('UPDATE ce_forum_profile_fields SET label=?,visibility=?,position=? WHERE id=?',[$label,$visibility,$position,$id]);
        else $this->query('INSERT INTO ce_forum_profile_fields(id,label,visibility,position) VALUES(?,?,?,?)',[$id,$label,$visibility,$position]);
    }
    public function deleteField(string $id): void { $this->query('DELETE FROM ce_forum_profile_fields WHERE id=?',[$id]); }
    public function imageKinds(string $id): array { return array_column($this->rows('SELECT kind FROM ce_forum_profile_images WHERE user_id=?',[$id]),'kind'); }
    public function image(string $id,string $kind): ?string { return $this->row('SELECT image_data FROM ce_forum_profile_images WHERE user_id=? AND kind=?',[$id,$kind])['image_data']??null; }
    public function saveImage(string $id,string $kind,?string $data): void
    {
        $this->query('DELETE FROM ce_forum_profile_images WHERE user_id=? AND kind=?',[$id,$kind]);
        if($data!==null)$this->query('INSERT INTO ce_forum_profile_images(user_id,kind,image_data) VALUES(?,?,?)',[$id,$kind,$data]);
    }
    public function groups(string $id): array
    {
        return $this->rows('SELECT r.id,r.name,CASE WHEN p.role_id=r.id THEN 1 ELSE 0 END AS is_primary FROM ce_user_roles ur JOIN ce_forum_groups g ON g.role_id=ur.role_id JOIN ce_roles r ON r.id=g.role_id LEFT JOIN ce_forum_primary_groups p ON p.user_id=ur.user_id WHERE ur.user_id=? ORDER BY is_primary DESC,r.name,r.id',[$id]);
    }
    public function directory(string $viewer,string $term,int $offset,string $sort,bool $discovery=false): array
    {
        $filter="u.status='active' AND (COALESCE(p.profile_visibility,'public')='public'";
        $args=[];
        if($viewer!==''){$filter.=" OR p.profile_visibility='members' OR u.id=?";$args[]=$viewer;}
        $filter.=')';
        if ($discovery) {
            $filter.=" AND (COALESCE(p.activity_visibility,'public')='public'";
            if($viewer!==''){$filter.=" OR p.activity_visibility='members' OR u.id=?";$args[]=$viewer;}
            $filter.=')';
            if($viewer!==''){$filter.=" AND NOT EXISTS (SELECT 1 FROM ce_forum_user_blocks b WHERE (b.user_id=? AND b.blocked_id=u.id) OR (b.blocked_id=? AND b.user_id=u.id)) AND NOT EXISTS (SELECT 1 FROM ce_forum_member_relations i WHERE i.user_id=? AND i.target_id=u.id AND i.kind='ignore')";array_push($args,$viewer,$viewer,$viewer);}
        }
        $name="COALESCE(NULLIF(m.display_name,''),u.name)";
        if($term!==''){$filter.=" AND LOWER($name) LIKE LOWER(?) ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';}
        $order=$sort==='newest'?'u.created_at DESC,u.id':"LOWER($name),u.id";
        return $this->rows("SELECT u.id,$name AS name,u.created_at FROM ce_users u LEFT JOIN ce_forum_member_profiles m ON m.user_id=u.id LEFT JOIN ce_forum_member_preferences p ON p.user_id=u.id WHERE $filter ORDER BY $order LIMIT ".($discovery?8:25)." OFFSET ".$offset,$args);
    }
}
