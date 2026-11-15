<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Forum\Repository\{ReportRepository, ThreadRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Support\Uuid;

final readonly class ModerationService
{
    public function __construct(private ForumTransaction $tx, private ReportRepository $reports, private ThreadRepository $threads) {}
    public function report(ForumAccessPolicy $actor, string $post, string $reason,string $category='other'): string
    {
        $post = V::id($post); $reason = V::text($reason,'reason',500,3);
        if(!in_array($category,['spam','abuse','privacy','unsafe_file','other'],true))throw new ForumException('Некорректная категория жалобы.');
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$post,$reason,$category) {
            $thread=$this->threads->lockForPost($post) ?? throw new ForumException('Сообщение не найдено.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'report:create');
            $existing=$this->reports->existing($post,$actor->userId);if($existing!==null)return $existing;
            $id = Uuid::v4(); $this->reports->insert($id,$post,$actor->userId,$reason,$category);$this->tx->moderationQueue()->reportEvent($id,$actor->userId,'submit','');
            $this->tx->audit('forum.report.create',$actor->userId,['report_id'=>$id,'post_id'=>$post]);
            return $id;
        });
    }
    public function reports(ForumAccessPolicy $actor, int $offset): array
    {
        $actor->require('forum:moderate');
        return $this->tx->read(function() use($actor,$offset) {
            $permissions=$this->tx->permissions();$permissions->requireCurrent($actor,'forum:moderate');
            return $this->reports->open($offset,NodePermissionService::allowed($permissions->access($actor),'thread:moderate'));
        });
    }
    public function resolve(ForumAccessPolicy $actor, string $id): string
    {
        $id = V::id($id);
        $actor->require('forum:moderate');
        return $this->tx->write(function () use ($actor,$id) {
            // Acquire the thread before the report, matching deletion's cascade lock order.
            $thread=$this->threads->lockForReport($id)??throw new ForumException('Жалоба не найдена.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'thread:moderate');
            $report=$this->reports->lockReport($id)??throw new ForumException('Жалоба не найдена.',404);
            // A repeated resolve is idempotent; do not overwrite the original reviewer.
            if ($report['status'] === 'open') {
                $this->reports->resolve($id,$actor->userId);$this->tx->moderationQueue()->reportEvent($id,$actor->userId,'resolve','Resolved',(int)$report['revision_number']+1);
                $this->tx->audit('forum.report.resolve',$actor->userId,['report_id'=>$id]);
            }
            return $id;
        });
    }
    public function recycle(): RecycleBinService { return $this->tx->recycle(); }
    public function moderate(ForumAccessPolicy $actor, string $id, string $action, string $reason=''): string
    {
        $id = V::id($id);
        $actor->require('forum:moderate');
        if (!in_array($action,['lock','unlock','pin','unpin','delete'],true)) throw new ForumException('Неизвестное действие.');
        if($action==='delete'){ $this->tx->recycle()->change($actor,'thread',$id,'delete',$reason); return $id; }
        return $this->tx->write(function () use ($actor,$id,$action) {
            $thread=$this->threads->lockThread($id) ?? throw new ForumException('Тема не найдена.',404);
            $this->tx->permissions()->assertWrite($actor,$thread['board_id'],'thread:moderate');
            $this->tx->notifications()->moderated($actor->userId,$thread,$action);
            $this->threads->moderate($id,$action);
            $this->tx->audit('forum.moderate',$actor->userId,['thread_id'=>$id,'action'=>$action]);
            return $id;
        });
    }
}
