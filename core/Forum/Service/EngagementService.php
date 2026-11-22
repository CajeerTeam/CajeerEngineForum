<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Forum\Repository\{EngagementRepository, ThreadRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;

final readonly class EngagementService
{
    public function __construct(private ForumTransaction $tx, private EngagementRepository $engagement, private ThreadRepository $threads) {}
    public function reaction(ForumAccessPolicy $actor, string $id, bool $enabled,mixed $type=null): array
    {
        $id = V::id($id);
        $type??='like';if(!is_string($type)||!isset(\CajeerEngine\Forum\Repository\ActivityRepository::TYPES[$type]))throw new ForumException('Некорректная реакция.',422);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$enabled,$type) {
            if($enabled)$this->tx->contacts()->lockWrites();
            $thread=$this->threads->lockForPost($id) ?? throw new ForumException('Объект не найден.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'reaction:use');
            if($enabled){$author=$this->tx->contacts()->postAuthor($id);if($author!==null&&$this->tx->contacts()->blocked($actor->userId,$author))throw new ForumException('Взаимодействие недоступно.',403);}
            if($enabled)$this->tx->antiSpam()->action($actor->userId,'reaction');
            if (($change=$this->engagement->reaction($id,$actor->userId,$enabled,$type))!=='unchanged') {
                if($change!=='changed')$this->tx->notifications()->reaction($actor->userId,$id,$enabled);
                $this->tx->audit('forum.reaction.set',$actor->userId,['post_id'=>$id,'enabled'=>$enabled,'type'=>$enabled?$type:null,'change'=>$change]);
            }
            return ['enabled'=>$enabled,'type'=>$enabled?$type:null];
        });
    }
    public function watch(ForumAccessPolicy $actor,string $id,bool $enabled,mixed $mode=null): array
    { return $this->tx->watches()->set($actor,'thread',$id,$enabled,$mode); }
    public function watches(ForumAccessPolicy $actor, int $offset): array
    {
        $actor->require('forum:write');
        return $this->tx->read(function() use($actor,$offset) {
            $permissions=$this->tx->permissions();$permissions->requireCurrent($actor,'forum:write');
            return $this->tx->readState()->decorateThreads($this->engagement->watches($actor->userId,$offset,NodePermissionService::allowed($permissions->access($actor))),$actor->userId);
        });
    }
}
