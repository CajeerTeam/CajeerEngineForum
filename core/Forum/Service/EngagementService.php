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
    public function reaction(ForumAccessPolicy $actor, string $id, bool $enabled): array
    {
        $id = V::id($id);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$enabled) {
            $thread=$this->threads->lockForPost($id) ?? throw new ForumException('Объект не найден.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'reaction:use');
            if ($this->engagement->reaction($id,$actor->userId,$enabled)) {
                $this->tx->notifications()->reaction($actor->userId,$id,$enabled);
                $this->tx->audit('forum.reaction.set',$actor->userId,['post_id'=>$id,'enabled'=>$enabled]);
            }
            return ['enabled'=>$enabled];
        });
    }
    public function watch(ForumAccessPolicy $actor, string $id, bool $enabled): array
    {
        $id = V::id($id);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$enabled) {
            $thread=$this->threads->lockThread($id) ?? throw new ForumException('Объект не найден.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'watch:use');
            if ($this->engagement->watch($id,$actor->userId,$enabled)) $this->tx->audit('forum.watch.set',$actor->userId,['thread_id'=>$id,'enabled'=>$enabled]);
            return ['enabled'=>$enabled];
        });
    }
    public function watches(ForumAccessPolicy $actor, int $offset): array
    {
        $actor->require('forum:write');
        return $this->tx->read(function() use($actor,$offset) {
            $permissions=$this->tx->permissions();$permissions->requireCurrent($actor,'forum:write');
            return $this->tx->readState()->decorateThreads($this->engagement->watches($actor->userId,$offset,NodePermissionService::allowed($permissions->access($actor))),$actor->userId);
        });
    }
}
