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

    public function index(): array
    {
        return $this->db->readSnapshot(fn () => ['categories'=>$this->boards->categories(), 'boards'=>$this->boards->boards()]);
    }
    public function board(string $id, int $offset): array
    {
        return $this->db->readSnapshot(function () use ($id, $offset) {
            $board = $this->boards->find($id) ?? throw new ForumException('Раздел не найден.', 404);
            return ['board'=>$board, 'threads'=>$this->threads->inBoard($id,$offset)];
        });
    }
    public function thread(string $id, int $offset): array
    {
        return $this->db->readSnapshot(function () use ($id, $offset) {
            $thread = $this->threads->find($id) ?? throw new ForumException('Тема не найдена.', 404);
            return ['thread'=>$thread, 'posts'=>$this->posts->inThread($id,$offset)];
        });
    }
    public function search(string $term, int $offset): array { return $this->threads->search($term,$offset); }
    public function latest(int $offset): array { return $this->threads->latest($offset); }
    public function profile(string $id, int $offset): array
    {
        return $this->db->readSnapshot(function () use ($id, $offset) {
            $member = $this->members->publicProfile($id) ?? throw new ForumException('Пользователь не найден.', 404);
            return ['member'=>$member, 'threads'=>$this->threads->byAuthor($id,$offset)];
        });
    }
    public function createThread(ForumAccessPolicy $actor, string $board, string $title, string $body): string
    {
        $board = V::id($board); $title = V::text($title,'title',180,3); $body = V::text($body,'body',20000);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$board,$title,$body) {
            $this->boards->lockBoard($board) ?? throw new ForumException('Раздел не найден.',404);
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
            $actor->assertEdit($post,$thread);
            $this->posts->edit($id,$body);
            $this->tx->audit('forum.post.edit',$actor->userId,['thread_id'=>$thread['id'],'post_id'=>$id]);
            return $id;
        });
    }
    public function category(ForumAccessPolicy $actor, string $name, string $description): string
    {
        $name = V::text($name,'name',120); $description = V::text($description,'description',1000,0);
        $actor->require('*');
        return $this->tx->write(function () use ($actor,$name,$description) {
            $id = Uuid::v4(); $this->boards->insertCategory($id,$name,$description);
            $this->tx->audit('forum.category.create',$actor->userId,['category_id'=>$id]);
            return $id;
        });
    }
    public function createBoard(ForumAccessPolicy $actor, string $category, string $name, string $description): string
    {
        $category = V::id($category); $name = V::text($name,'name',120); $description = V::text($description,'description',1000,0);
        $actor->require('*');
        return $this->tx->write(function () use ($actor,$category,$name,$description) {
            $this->boards->lockCategory($category) ?? throw new ForumException('Категория не найдена.',404);
            $id = Uuid::v4(); $this->boards->insertBoard($id,$category,$name,$description);
            $this->tx->audit('forum.board.create',$actor->userId,['board_id'=>$id,'category_id'=>$category]);
            return $id;
        });
    }
}
