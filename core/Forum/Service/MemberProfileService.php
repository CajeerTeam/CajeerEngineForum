<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\ProfileRepository as Repo;
use CajeerEngine\Forum\Security\{ForumAccessPolicy,ProfileImage};
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class MemberProfileService
{
    public function __construct(private ForumTransaction $tx,private Repo $repo) {}
    public static function visible(string $visibility,string $viewer,string $owner): bool
    { return $viewer===$owner || $visibility==='public' || ($visibility==='members' && $viewer!==''); }
    private function viewer(ForumAccessPolicy $actor): string
    {
        if($actor->userId!=='' && $this->repo->user($actor->userId)===null)throw new ForumException('Требуется авторизация.',401);
        return $actor->userId;
    }
    private function lockActive(ForumAccessPolicy $actor): void
    {
        $u=$this->repo->lockUser($actor->userId);
        if(!$u||$u['status']!=='active')throw new ForumException('Требуется авторизация.',401);
    }
    private static function visibility(mixed $value): string
    { if(!is_string($value)||!in_array($value,['public','members','private'],true))throw new ForumException('Некорректная видимость.',422);return $value; }
    private static function imageUrl(string $id,string $kind): string { return '/api/v1/forum/members/'.$id.'/images/'.$kind; }
    private function images(string $id): array
    { $result=['avatar_url'=>null,'cover_url'=>null];foreach($this->repo->imageKinds($id) as $kind)$result[$kind.'_url']=self::imageUrl($id,$kind);return $result; }
    public function publicProfile(string $id,ForumAccessPolicy $actor): array
    {
        $viewer=$this->viewer($actor);$member=$this->repo->user($id)??throw new ForumException('Пользователь не найден.',404);
        $prefs=$this->repo->preferences($id);
        if(!self::visible($prefs['profile_visibility'],$viewer,$id))throw new ForumException('Пользователь не найден.',404);
        $profile=$this->repo->profile($id);
        $member['name']=$profile['display_name']!==''?$profile['display_name']:$member['name'];
        return $member+$profile+$this->images($id)+['activity_visible'=>self::visible($prefs['activity_visibility'],$viewer,$id),'groups'=>((int)$prefs['show_groups']===1||$viewer===$id)?$this->repo->groups($id):[], 'fields'=>array_values(array_filter($this->repo->values($id),fn($f)=>self::visible($f['visibility'],$viewer,$id)))];
    }
    public function directory(ForumAccessPolicy $actor,mixed $term,int $offset,mixed $sort): array
    {
        $q=V::text($term,'q',100,0);
        if(!in_array($sort,['name','newest'],true))throw new ForumException('Некорректная сортировка.',422);
        return $this->tx->read(fn()=> $this->repo->directory($this->viewer($actor),$q,$offset,$sort));
    }
    public function own(ForumAccessPolicy $actor): array
    {
        return $this->tx->read(function()use($actor){$id=$this->viewer($actor);if($id==='')throw new ForumException('Требуется авторизация.',401);
            $prefs=$this->repo->preferences($id);foreach(['show_groups','show_signatures'] as $k)$prefs[$k]=(bool)$prefs[$k];
            return ['profile'=>$this->repo->profile($id),'preferences'=>$prefs,'images'=>$this->images($id),'fields'=>$this->repo->fields(),'values'=>$this->repo->values($id),'groups'=>$this->repo->groups($id)];});
    }
    public function update(ForumAccessPolicy $actor,array $input): array
    {
        $data=[];foreach(['display_name'=>60,'bio'=>2000,'location'=>120,'website'=>500,'signature'=>500] as $key=>$max)if(array_key_exists($key,$input))$data[$key]=V::text($input[$key],$key,$max,0);
        if(isset($data['website'])&&$data['website']!==''){
            $url=parse_url($data['website']);if(!filter_var($data['website'],FILTER_VALIDATE_URL)||!is_array($url)||!in_array(strtolower($url['scheme']??''),['http','https'],true)||isset($url['user'])||isset($url['pass']))throw new ForumException('Укажите HTTP/HTTPS URL без учётных данных.',422);
        }
        $values=[];if(array_key_exists('fields',$input)){
            if(!is_array($input['fields'])||!array_is_list($input['fields'])||count($input['fields'])>32)throw new ForumException('Некорректные поля профиля.',422);
            foreach($input['fields'] as $field){if(!is_array($field))throw new ForumException('Некорректное поле.',422);$id=V::id($field['id']??null);if(isset($values[$id]))throw new ForumException('Повтор поля.',422);$values[$id]=V::text($field['value']??null,'value',500,0);}
        }
        $this->tx->write(function()use($actor,$data,$values){
            if($values!==[])$this->repo->lockFields();$this->lockActive($actor);$id=$actor->userId;
            if($data!==[])$this->repo->save($id,'profile',$data+$this->repo->profile($id));
            if($values!==[]){$valid=array_column($this->repo->fields(),'id');if(array_diff(array_keys($values),$valid))throw new ForumException('Поле профиля не найдено.',422);$this->repo->saveValues($id,$values);}
            $this->tx->audit('forum.profile.update',$id,['user_id'=>$id]);
        });return $this->own($actor);
    }
    public function preferences(ForumAccessPolicy $actor,array $input): array
    {
        $data=[];foreach(['profile_visibility','activity_visibility'] as $key)if(array_key_exists($key,$input))$data[$key]=self::visibility($input[$key]);
        foreach(['show_groups','show_signatures'] as $key)if(array_key_exists($key,$input))$data[$key]=V::enabled($input[$key])?1:0;
        foreach(['theme'=>['system','light','dark'],'locale'=>['ru-RU','en-US']] as $key=>$allowed)if(array_key_exists($key,$input)){if(!in_array($input[$key],$allowed,true))throw new ForumException('Некорректное поле: '.$key,422);$data[$key]=$input[$key];}
        if(array_key_exists('timezone',$input)){if(!is_string($input['timezone'])||!in_array($input['timezone'],\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true))throw new ForumException('Некорректный часовой пояс.',422);$data['timezone']=$input['timezone'];}
        $this->tx->write(function()use($actor,$data){$this->lockActive($actor);$id=$actor->userId;if($data!==[])$this->repo->save($id,'preferences',$data+$this->repo->preferences($id));$this->tx->audit('forum.profile.preferences',$id,['user_id'=>$id]);});return $this->own($actor);
    }
    public function setImage(ForumAccessPolicy $actor,string $kind,mixed $input): array
    {
        if(!in_array($kind,['avatar','cover'],true))throw new ForumException('Некорректный тип изображения.',422);
        // Authenticate before spending CPU decoding an image.
        if($this->viewer($actor)==='')throw new ForumException('Требуется авторизация.',401);
        $data=$input===null?null:ProfileImage::encode($kind,$input);
        $this->tx->write(function()use($actor,$kind,$data){$this->lockActive($actor);$this->repo->saveImage($actor->userId,$kind,$data);$this->tx->audit('forum.profile.image',$actor->userId,['kind'=>$kind,'removed'=>$data===null]);});return $this->own($actor);
    }
    public function image(ForumAccessPolicy $actor,string $id,string $kind): string
    {
        if(!in_array($kind,['avatar','cover'],true))throw new ForumException('Изображение не найдено.',404);
        return $this->tx->read(function()use($actor,$id,$kind){$viewer=$this->viewer($actor);if(!$this->repo->user($id)||!self::visible($this->repo->preferences($id)['profile_visibility'],$viewer,$id))throw new ForumException('Изображение не найдено.',404);
            $data=$this->repo->image($id,$kind);if($data===null||($bytes=base64_decode($data,true))===false)throw new ForumException('Изображение не найдено.',404);return $bytes;});
    }
    public function fields(ForumAccessPolicy $actor): array { return $this->tx->read(function()use($actor){$this->tx->permissions()->requireAdmin($actor);return $this->repo->fields();}); }
    public function saveField(ForumAccessPolicy $actor,?string $id,array $input): array
    {
        $label=V::text($input['label']??null,'label',80);$visibility=self::visibility($input['visibility']??null);$position=$input['position']??0;
        if(!is_int($position)||$position<0||$position>10000)throw new ForumException('Некорректная позиция.',422);
        return $this->tx->write(function()use($actor,$id,$label,$visibility,$position){$this->repo->lockFields();$this->tx->permissions()->requireAdmin($actor,true);$fields=$this->repo->fields();
            if($id===null&&count($fields)>=32)throw new ForumException('Максимум 32 поля.',409);
            if($id!==null&&!in_array($id,array_column($fields,'id'),true))throw new ForumException('Поле не найдено.',404);
            $id??=Uuid::v4();$this->repo->saveField($id,$label,$visibility,$position);$this->tx->audit('forum.profile.field.save',$actor->userId,['field_id'=>$id]);return ['id'=>$id];});
    }
    public function deleteField(ForumAccessPolicy $actor,string $id): array
    {return $this->tx->write(function()use($actor,$id){$this->repo->lockFields();$this->tx->permissions()->requireAdmin($actor,true);if(!in_array($id,array_column($this->repo->fields(),'id'),true))throw new ForumException('Поле не найдено.',404);$this->repo->deleteField($id);$this->tx->audit('forum.profile.field.delete',$actor->userId,['field_id'=>$id]);return ['id'=>$id];});}
    public function moderate(ForumAccessPolicy $actor,string $id,mixed $field): array
    {
        // Profile moderation is global; delegated node moderators must not gain it.
        if(!in_array($field,['bio','signature','avatar','cover'],true))throw new ForumException('Некорректное поле.',422);
        return $this->tx->write(function()use($actor,$id,$field){$this->repo->lockFields();$this->tx->permissions()->requireAdmin($actor,true);if(!$this->repo->lockUser($id))throw new ForumException('Пользователь не найден.',404);
            if(in_array($field,['avatar','cover'],true))$this->repo->saveImage($id,$field,null);else $this->repo->save($id,'profile',[$field=>'']+$this->repo->profile($id));
            $this->tx->audit('forum.profile.moderate',$actor->userId,['user_id'=>$id,'field'=>$field]);return ['id'=>$id];});
    }
}
