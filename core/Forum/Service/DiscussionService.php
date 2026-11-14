<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Forum\Repository\{BoardRepository, ThreadRepository, PostRepository, MemberRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Support\Uuid;

final readonly class DiscussionService
{
    public function __construct(private DatabaseManager $db, private ForumTransaction $tx, private BoardRepository $boards, private ThreadRepository $threads, private PostRepository $posts, private MemberRepository $members) {}

    private function access(?ForumAccessPolicy $actor): array { return $this->tx->permissions()->access($actor??new ForumAccessPolicy('',[])); }
    private function decorated(array $board,array $access): array { $board['permissions']=$access['boards'][$board['id']]; return $board; }
    public function index(?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($actor) {
            $access=$this->access($actor);$allowed=NodePermissionService::allowed($access);$categories=NodePermissionService::allowed($access,'thread:read','categories');
            return ['categories'=>$this->boards->categories($categories), 'boards'=>array_map(fn($b)=>$this->decorated($b,$access),$this->boards->boards($allowed))];
        });
    }
    public function board(string $id, int $offset, ?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $access=$this->access($actor);NodePermissionService::assert($access,$id,'thread:read');
            $board=$this->boards->view($id)??throw new ForumException('Раздел не найден.',404);
            $children=$this->boards->children($id,NodePermissionService::allowed($access));
            return ['board'=>$this->decorated($board,$access),'threads'=>$this->threads->inBoard($id,$offset),'children'=>array_map(fn($b)=>$this->decorated($b,$access),$children)];
        });
    }
    public function thread(string $id, int $offset, ?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $thread=$this->threads->find($id)??throw new ForumException('Тема не найдена.',404);
            $access=$this->access($actor);NodePermissionService::assert($access,$thread['board_id'],'thread:read');
            return ['thread'=>$thread,'board'=>$this->decorated($this->boards->view($thread['board_id'])??throw new ForumException('Раздел не найден.',404),$access),'posts'=>$this->posts->inThread($id,$offset)];
        });
    }
    public function search(string $term,int $offset,?ForumAccessPolicy $actor=null): array { return $this->db->readSnapshot(fn()=> $this->threads->search($term,$offset,NodePermissionService::allowed($this->access($actor)))); }
    public function latest(int $offset,?ForumAccessPolicy $actor=null): array { return $this->db->readSnapshot(fn()=> $this->threads->latest($offset,NodePermissionService::allowed($this->access($actor)))); }
    public function profile(string $id,int $offset,?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $member=$this->members->publicProfile($id)??throw new ForumException('Пользователь не найден.',404);
            return ['member'=>$member,'threads'=>$this->threads->byAuthor($id,$offset,NodePermissionService::allowed($this->access($actor)))];
        });
    }
    public function groups(): GroupService { return $this->tx->groups(); }
    public function permissions(): NodePermissionService { return $this->tx->permissions(); }
    public function createThread(ForumAccessPolicy $actor, string $board, string $title, string $body): string
    {
        $board = V::id($board); $title = V::text($title,'title',180,3); $body = V::text($body,'body',20000);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$board,$title,$body) {
            $this->permissions()->assertWrite($actor,$board,'thread:create');
            $this->boards->writable($board);
            $id = Uuid::v4(); $post = Uuid::v4();
            $this->threads->insert($id,$board,$actor->userId,$title);
            $this->posts->insert($post,$id,$actor->userId,$body,1);
            $this->tx->audit('forum.thread.create',$actor->userId,['thread_id'=>$id,'board_id'=>$board,'post_id'=>$post]);
            return $id;
        });
    }
    public function reply(ForumAccessPolicy $actor, string $id, string $body): string
    {
        $id = V::id($id); $body = V::text($body,'body',20000);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$body) {
            $thread = $this->threads->lockThread($id) ?? throw new ForumException('Тема не найдена.',404);
            $this->permissions()->assertWrite($actor,$thread['board_id'],'post:reply');
            $this->boards->writable($thread['board_id']);
            if ((int) $thread['locked'] !== 0) throw new ForumException('Тема закрыта.',409);
            $post = Uuid::v4();
            $this->posts->insert($post,$id,$actor->userId,$body,$this->posts->nextOrdinal($id));
            $this->threads->touch($id);
            $this->tx->audit('forum.post.reply',$actor->userId,['thread_id'=>$id,'post_id'=>$post]);
            return $post;
        });
    }
    public function edit(ForumAccessPolicy $actor, string $id, string $body): string
    {
        $id = V::id($id); $body = V::text($body,'body',20000);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$body) {
            $thread = $this->threads->lockForPost($id) ?? throw new ForumException('Сообщение не найдено.',404);
            $post = $this->posts->findForWrite($id) ?? throw new ForumException('Сообщение не найдено.',404);
            $this->permissions()->assertWrite($actor,$thread['board_id'],$post['author_id']===$actor->userId && (int)$thread['locked']===0?'post:edit_own':'post:edit_any');
            $this->boards->writable($thread['board_id']);
            $actor->assertEdit($post,$thread);
            $this->posts->edit($id,$body);
            $this->tx->audit('forum.post.edit',$actor->userId,['thread_id'=>$thread['id'],'post_id'=>$id]);
            return $id;
        });
    }
    public function nodes(): NodeService { return new NodeService($this->tx,$this->boards); }
    public function category(ForumAccessPolicy $actor, string $name, string $description): string
    {
        return $this->nodes()->createCategory($actor,$name,$description);
    }
    public function createBoard(ForumAccessPolicy $actor, string $category, string $name, string $description): string
    {
        return $this->nodes()->createBoard($actor,$category,$name,$description);
    }
}
