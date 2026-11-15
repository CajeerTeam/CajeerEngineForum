<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{SanctionRepository,PermissionRepository,BoardRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class SanctionService
{
    public const KINDS=['warning','posting','conversation','upload','reaction','read_only','suspension'];
    public function __construct(private ForumTransaction $tx,private SanctionRepository $repo,private PermissionRepository $users,private BoardRepository $boards) {}
    public static function blocks(array $rows,string $action,?string $board=null): bool
    {
        foreach($rows as $r){if($r['board_id']!==null&&$r['board_id']!==$board)continue;
            if($r['kind']==='suspension'||($r['kind']==='read_only'&&in_array($action,['posting','conversation','upload','reaction'],true))||$r['kind']===$action)return true;}
        return false;
    }
    // Called only after the principal row is locked by the business operation.
    public function enforce(string $user,string $action,?string $board=null,bool $lock=true): void
    {if($user!==''&&self::blocks($this->repo->active($user,time(),$lock),$action,$board))throw new ForumException('Действие ограничено действующей санкцией. /forum/sanctions',403);}
    public function suspended(string $user,bool $lock=false): bool
    {return self::blocks($this->repo->active($user,time(),$lock),'suspension');}
    /** Configurable crossing thresholds; board warnings never grant global authority. */
    public static function automatic(int $before,int $after,int $post=5,int $suspend=10): ?array
    {
        if($suspend>0&&$before<$suspend&&$after>=$suspend)return ['suspension',604800];
        if($post>0&&$before<$post&&$after>=$post)return ['posting',86400];
        return null;
    }
    private static function threshold(string $key,int $default): int
    {$raw=getenv($key);return $raw===false||!preg_match('/^\d{1,3}$/D',$raw)?$default:(int)$raw;}
    private function authority(ForumAccessPolicy $actor,?string $board,bool $write): void
    {
        if($board===null){$this->tx->permissions()->requireAdmin($actor,$write);return;}
        if($write)$this->boards->lockBoard($board)??throw new ForumException('Раздел не найден.',404);
        $this->tx->permissions()->requireCurrent($actor,'forum:moderate',$write);
        if($actor->has('*'))$this->tx->permissions()->requireAdmin($actor,$write);
        NodePermissionService::assert($this->tx->permissions()->access($actor),$board,'thread:moderate');
    }
    private function target(string $user,ForumAccessPolicy $actor): void
    {
        if($user===$actor->userId)throw new ForumException('Нельзя назначать санкции себе.',403);
        $this->users->lockActor($user)??throw new ForumException('Участник не найден.',404);
        foreach($this->users->roleRecords($user,true) as $r){$scopes=json_decode($r['permissions'],true,512,JSON_THROW_ON_ERROR);if(in_array('*',$scopes,true)||(!$actor->has('*')&&in_array('forum:moderate',$scopes,true)))throw new ForumException('Защищённый аккаунт.',403);}
    }
    private function own(ForumAccessPolicy $actor,bool $lock=false): void
    { $u=$lock?$this->users->lockActor($actor->userId):$this->users->inspectUser($actor->userId);if(!$u||$u['status']!=='active')throw new ForumException('Требуется авторизация.',401); }
    private function normalized(array $r): array
    {foreach(['points','starts_at','ends_at','revoked_at'] as $k)if(isset($r[$k]))$r[$k]=(int)$r[$k];$r['active']=$r['revoked_at']===null&&$r['starts_at']<=time()&&($r['ends_at']===null||$r['ends_at']>time());return $r;}
    private function moderatorBoards(ForumAccessPolicy $actor): ?array
    {
        $this->tx->permissions()->requireCurrent($actor,'forum:moderate');
        if($actor->has('*')){$this->tx->permissions()->requireAdmin($actor);return null;}
        return NodePermissionService::allowed($this->tx->permissions()->access($actor),'thread:moderate');
    }
    public function listing(ForumAccessPolicy $actor,string $user,int $offset): array
    {return $this->tx->read(function()use($actor,$user,$offset){$this->own($actor);$boards=$actor->userId===$user?null:$this->moderatorBoards($actor);return array_map($this->normalized(...),$this->repo->list(V::id($user),$offset,$boards));});}
    public function issue(ForumAccessPolicy $actor,string $user,array $p): array
    {
        $user=V::id($user);$kind=$p['kind']??null;if(!in_array($kind,self::KINDS,true))throw new ForumException('Некорректный тип санкции.');
        $board=isset($p['board_id'])?V::id($p['board_id']):null;if($board!==null&&!in_array($kind,['warning','posting','reaction'],true))throw new ForumException('Недопустимая область санкции.');
        $reason=V::text($p['reason']??null,'reason',500,3);$points=$p['points']??($kind==='warning'?1:0);if(!is_int($points)||($kind==='warning'?($points<1||$points>100):$points!==0))throw new ForumException('Некорректные баллы.');
        $duration=$p['duration_seconds']??null;if($duration!==null&&(!is_int($duration)||$duration<60||$duration>31536000))throw new ForumException('Некорректный срок.');
        return $this->tx->write(function()use($actor,$user,$kind,$board,$reason,$points,$duration){
            $this->authority($actor,$board,true);$this->target($user,$actor);$now=time();$id=Uuid::v4();
            $before=0;if($kind==='warning'&&$board===null)foreach($this->repo->active($user,$now,true) as $old)if($old['kind']==='warning'&&$old['board_id']===null)$before+=(int)$old['points'];
            $this->repo->insert(['id'=>$id,'user_id'=>$user,'issuer_id'=>$actor->userId,'board_id'=>$board,'kind'=>$kind,'reason'=>$reason,'points'=>$points,'starts_at'=>$now,'ends_at'=>$duration===null?null:$now+$duration,'source_warning_id'=>null]);
            if($kind==='suspension')$this->repo->revokeSessions($user);
            if($kind==='warning'&&$board===null&&($auto=self::automatic($before,$before+$points,self::threshold('FORUM_WARNING_POST_POINTS',5),self::threshold('FORUM_WARNING_SUSPEND_POINTS',10)))!==null){
                [$type,$seconds]=$auto;$derived=Uuid::v4();$this->repo->insert(['id'=>$derived,'user_id'=>$user,'issuer_id'=>$actor->userId,'board_id'=>null,'kind'=>$type,'reason'=>'Автоматическое ограничение по баллам предупреждений','points'=>0,'starts_at'=>$now,'ends_at'=>$now+$seconds,'source_warning_id'=>$id]);
                if($type==='suspension')$this->repo->revokeSessions($user);
                $this->tx->audit('forum.sanction.automatic',$actor->userId,['id'=>$derived,'warning_id'=>$id,'user_id'=>$user,'kind'=>$type]);
            }
            $this->tx->audit('forum.sanction.issue',$actor->userId,['id'=>$id,'user_id'=>$user,'kind'=>$kind,'board_id'=>$board]);return $this->normalized($this->repo->item($id));
        });
    }
    public function revoke(ForumAccessPolicy $actor,string $id,array $p): array
    {return $this->tx->write(function()use($actor,$id,$p){$r=$this->repo->item(V::id($id))??throw new ForumException('Санкция не найдена.',404);$this->authority($actor,$r['board_id'],true);$this->users->lockActor($r['user_id'])??throw new ForumException('Участник не найден.',404);$r=$this->repo->lockItem($id);if(!$r||!$this->repo->revoke($id,$actor->userId,V::text($p['reason']??null,'reason',500,3),time()))throw new ForumException('Санкция уже отменена.',409);$this->repo->revokeDerived($id,$actor->userId,V::text($p['reason']??null,'reason',500,3),time());$this->tx->audit('forum.sanction.revoke',$actor->userId,['id'=>$id]);return $this->normalized($this->repo->item($id));});}
    private function appealRow(array $r): array {foreach(['created_at','decided_at'] as $k)if(isset($r[$k]))$r[$k]=(int)$r[$k];return $r;}
    public function appeal(ForumAccessPolicy $actor,string $id,array $p): array
    {return $this->tx->write(function()use($actor,$id,$p){$this->own($actor,true);$r=$this->repo->lockItem(V::id($id));if(!$r||$r['user_id']!==$actor->userId)throw new ForumException('Санкция не найдена.',404);$body=V::text($p['body']??null,'body',2000,3);$old=$this->repo->appeal($id);if($old){if($old['body']===$body)return $this->appealRow($old);throw new ForumException('Апелляция уже существует.',409);}$this->repo->insertAppeal(Uuid::v4(),$id,$body,time());$this->tx->audit('forum.sanction.appeal',$actor->userId,['id'=>$id]);return $this->appealRow($this->repo->appeal($id));});}
    public function appeals(ForumAccessPolicy $actor,int $offset): array
    {return $this->tx->read(function()use($actor,$offset){return array_map($this->appealRow(...),$this->repo->appeals($this->moderatorBoards($actor),$offset));});}
    public function decide(ForumAccessPolicy $actor,string $id,array $p): array
    {return $this->tx->write(function()use($actor,$id,$p){$r=$this->repo->item(V::id($id))??throw new ForumException('Санкция не найдена.',404);$this->authority($actor,$r['board_id'],true);$this->users->lockActor($r['user_id'])??throw new ForumException('Участник не найден.',404);$r=$this->repo->lockItem($id)??throw new ForumException('Санкция не найдена.',404);$status=$p['status']??null;if(!in_array($status,['accepted','rejected'],true))throw new ForumException('Некорректное решение.');$reason=V::text($p['reason']??null,'reason',500,3);if(!$this->repo->decide($id,$status,$actor->userId,$reason,time()))throw new ForumException('Апелляция уже рассмотрена или отсутствует.',409);if($status==='accepted'){if($r['revoked_at']===null)$this->repo->revoke($id,$actor->userId,$reason,time());$this->repo->revokeDerived($id,$actor->userId,$reason,time());}$this->tx->audit('forum.sanction.appeal.decide',$actor->userId,['id'=>$id,'status'=>$status]);return $this->appealRow($this->repo->appeal($id));});}
}
