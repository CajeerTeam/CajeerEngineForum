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
    public function report(ForumAccessPolicy $actor, string $post, string $reason): string
    {
        $post = V::id($post); $reason = V::text($reason,'reason',500,3);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$post,$reason) {
            $this->threads->lockForPost($post) ?? throw new ForumException('Сообщение не найдено.',404);
            $id = Uuid::v4(); $this->reports->insert($id,$post,$actor->userId,$reason);
            $this->tx->audit('forum.report.create',$actor->userId,['report_id'=>$id,'post_id'=>$post]);
            return $id;
        });
    }
    public function reports(ForumAccessPolicy $actor, int $offset): array
    {
        $actor->require('forum:moderate');
        return $this->reports->open($offset);
    }
    public function resolve(ForumAccessPolicy $actor, string $id): string
    {
        $id = V::id($id);
        $actor->require('forum:moderate');
        return $this->tx->write(function () use ($actor,$id) {
            $report = $this->reports->lockReport($id) ?? throw new ForumException('Жалоба не найдена.',404);
            // A repeated resolve is idempotent; do not overwrite the original reviewer.
            if ($report['status'] === 'open') {
                $this->reports->resolve($id,$actor->userId);
                $this->tx->audit('forum.report.resolve',$actor->userId,['report_id'=>$id]);
            }
            return $id;
        });
    }
    public function moderate(ForumAccessPolicy $actor, string $id, string $action): string
    {
        $id = V::id($id);
        $actor->require('forum:moderate');
        if (!in_array($action,['lock','unlock','pin','unpin','delete'],true)) throw new ForumException('Неизвестное действие.');
        return $this->tx->write(function () use ($actor,$id,$action) {
            $this->threads->lockThread($id) ?? throw new ForumException('Тема не найдена.',404);
            $this->threads->moderate($id,$action);
            $this->tx->audit('forum.moderate',$actor->userId,['thread_id'=>$id,'action'=>$action]);
            return $id;
        });
    }
}
