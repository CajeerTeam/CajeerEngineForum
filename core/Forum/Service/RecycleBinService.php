<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{RecycleBinRepository,BoardRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class RecycleBinService
{
    public function __construct(private ForumTransaction $tx,private RecycleBinRepository $repo,private BoardRepository $boards) {}
    public static function retentionDays(): int
    {return max(1,min(365,(int)(getenv('FORUM_RECYCLE_RETENTION_DAYS')?:30)));}
    private static function kind(string $kind): string
    {if(!in_array($kind,['thread','post'],true))throw new ForumException('Неизвестный тип содержимого.',422);return $kind;}
    public function listing(ForumAccessPolicy $actor,int $offset,string $kind='all'): array
    {
        if($kind!=='all')self::kind($kind);
        $actor->require('forum:moderate');
        return $this->tx->read(function()use($actor,$offset,$kind){
            $this->tx->permissions()->requireCurrent($actor,'forum:moderate');
            $access=$this->tx->permissions()->access($actor);
            $allowed=array_values(array_intersect(NodePermissionService::allowed($access,'thread:read'),NodePermissionService::allowed($access,'thread:moderate')));
            return ['items'=>$this->repo->listing($allowed,$offset,$kind),'retention_days'=>self::retentionDays(),'can_purge'=>$actor->has('*')];
        });
    }
    public function detail(ForumAccessPolicy $actor,string $kind,string $id,int $offset=0): array
    {
        self::kind($kind);$id=V::id($id);$actor->require('forum:moderate');
        return $this->tx->read(function()use($actor,$kind,$id,$offset){
            $this->tx->permissions()->requireCurrent($actor,'forum:moderate');
            $row=$this->repo->target($kind,$id)??throw new ForumException('Содержимое не найдено в корзине.',404);
            $access=$this->tx->permissions()->access($actor);
            NodePermissionService::assert($access,$row['board_id'],'thread:read');
            NodePermissionService::assert($access,$row['board_id'],'thread:moderate');
            return ['item'=>$row,'posts'=>$this->repo->contents($kind,$id,$offset),'has_open_reports'=>$this->repo->hasOpenReports($kind,$id)];
        });
    }
    public function change(ForumAccessPolicy $actor,string $kind,string $id,string $action,string $reason='',?string $generation=null): array
    {
        self::kind($kind);$id=V::id($id);
        if(!in_array($action,['delete','restore','purge'],true))throw new ForumException('Неизвестное действие.',422);
        $actor->require($action==='purge'?'*':'forum:moderate');
        $reason=V::text($reason,'reason',500,0);
        if($action!=='delete')$generation=V::id($generation??'');
        return $this->tx->write(function()use($actor,$kind,$id,$action,$reason,$generation){
            $row=$this->repo->lockTarget($kind,$id)??throw new ForumException('Содержимое не найдено.',404);
            $this->boards->lockBoard($row['board_id'])??throw new ForumException('Раздел не найден.',404);
            $this->tx->permissions()->requireCurrent($actor,$action==='purge'?'*':'forum:moderate',true);
            $access=$this->tx->permissions()->access($actor,lockActor:true);
            NodePermissionService::assert($access,$row['board_id'],'thread:read');
            NodePermissionService::assert($access,$row['board_id'],'thread:moderate');
            if($kind==='post'){
                if($row['parent_deleted'])throw new ForumException('Сначала восстановите тему.',409);
                if($this->repo->first($row['thread_id'])===$id)throw new ForumException('Первое сообщение удаляется только вместе с темой.',409);
            }
            if($action==='delete'){
                if($row['deleted_at']!==null)return ['id'=>$id,'kind'=>$kind,'deleted'=>true,'deletion_id'=>$row['deletion_id']];
                $generation=\CajeerEngine\Support\Uuid::v4();$this->repo->hide($kind,$id,$actor->userId,$reason,time(),$generation);$this->repo->suppress($kind,$id);
            }else{
                if($row['deleted_at']===null)throw new ForumException('Объект не находится в корзине.',409);
                if($row['deletion_id']!==$generation)throw new ForumException('Состояние корзины изменилось. Обновите страницу.',409);
                if($action==='restore')$this->repo->restore($kind,$id);
                else{
                    if($this->repo->hasOpenReports($kind,$id))throw new ForumException('Сначала рассмотрите открытые жалобы.',409);
                    $this->repo->purge($kind,$id);
                }
            }
            $this->tx->audit('forum.recycle.'.$action,$actor->userId,['kind'=>$kind,'id'=>$id,'board_id'=>$row['board_id'],'reason'=>$reason]);
            return ['id'=>$id,'kind'=>$kind,'deleted'=>$action==='delete','purged'=>$action==='purge','deletion_id'=>$action==='delete'?$generation:null];
        });
    }
    public function cleanup(): int
    {
        $cutoff=time()-self::retentionDays()*86400;$count=0;
        foreach($this->repo->expired($cutoff) as $candidate){
            $count+=(int)$this->tx->write(function()use($candidate,$cutoff){
                $kind=$candidate['kind'];$id=$candidate['id'];$row=$this->repo->lockTarget($kind,$id);
                if(!$row||$row['deleted_at']===null||(int)$row['deleted_at']>$cutoff||($row['parent_deleted']??false)||$this->repo->hasOpenReports($kind,$id))return false;
                if($kind==='post'&&$this->repo->first($row['thread_id'])===$id)return false;
                if(!$this->repo->purge($kind,$id))return false;
                $this->tx->audit('forum.recycle.expire',null,['kind'=>$kind,'id'=>$id]);return true;
            });
        }
        return $count;
    }
}
