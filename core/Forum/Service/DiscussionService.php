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
    public function readTracking(): ReadTrackingService { return new ReadTrackingService($this->tx,$this->tx->readState(),$this->boards,$this->threads); }
    private function readBoards(array $boards,array $allowed,string $viewer): array
    {
        if ($viewer==='') return $boards;
        $counts=$this->tx->readState()->boardCounts($viewer,$allowed);
        $topology=array_column($this->boards->topology(),null,'id');
        $readable=array_fill_keys($allowed,true);$totals=[];
        // Propagate each nonzero count up at most 16 accessible ancestors.
        foreach($counts as $id=>$count) {
            $seen=[];
            while(isset($readable[$id],$topology[$id]) && !isset($seen[$id]) && count($seen)<16) {
                $seen[$id]=true;$totals[$id]=($totals[$id]??0)+(int)$count;
                $id=$topology[$id]['parent_id']??'';
            }
        }
        foreach($boards as &$board) $board['unread_threads']=$totals[$board['id']]??0;
        unset($board);
        return $boards;
    }
    /** Build the full path from the same ACL-protected snapshot as the content. */
    private function breadcrumbs(array $board,array $access): array
    {
        $path=[];$seen=[];$categoryId=$board['category_id'];
        while (true) {
            NodePermissionService::assert($access,$board['id'],'thread:read');
            if (isset($seen[$board['id']]) || count($seen)>=16 || $board['category_id']!==$categoryId) throw new ForumException('Раздел не найден.',404);
            $seen[$board['id']]=true;
            $path[]=['kind'=>'board','id'=>$board['id'],'name'=>$board['name']];
            if ($board['parent_id']===null) break;
            $board=$this->boards->find($board['parent_id'])??throw new ForumException('Раздел не найден.',404);
        }
        $categories=$this->boards->categories([$categoryId]);
        if (!in_array($categoryId,NodePermissionService::allowed($access,'thread:read','categories'),true) || !$categories) throw new ForumException('Раздел не найден.',404);
        $path[]=['kind'=>'category','id'=>$categoryId,'name'=>$categories[0]['name']];
        return array_reverse($path);
    }
    public function index(?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($actor) {
            $access=$this->access($actor);$allowed=NodePermissionService::allowed($access);$categories=NodePermissionService::allowed($access,'thread:read','categories');
            return ['categories'=>$this->boards->categories($categories), 'boards'=>$this->readBoards(array_map(fn($b)=>$this->decorated($b,$access),$this->boards->boards($allowed)),$allowed,$actor?->userId??'')];
        });
    }
    public function board(string $id, int $offset, ?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $access=$this->access($actor);NodePermissionService::assert($access,$id,'thread:read');
            $board=$this->boards->view($id)??throw new ForumException('Раздел не найден.',404);
            $allowed=NodePermissionService::allowed($access);$viewer=$actor?->userId??'';
            $children=$this->boards->children($id,$allowed);
            foreach ($children as &$child) $child['effective_state']=($this->boards->view($child['id'])??$child)['effective_state']??'archived';
            unset($child);
            $nodes=$this->readBoards([$this->decorated($board,$access),...array_map(fn($b)=>$this->decorated($b,$access),$children)],$allowed,$viewer);
            $current=array_shift($nodes);
            return ['board'=>$current,'breadcrumbs'=>$this->breadcrumbs($board,$access),'threads'=>$this->tx->readState()->decorateThreads($this->threads->inBoard($id,$offset,$viewer),$viewer),'children'=>$nodes];
        });
    }
    public function thread(string $id, int $offset, ?ForumAccessPolicy $actor=null, bool $firstUnread=false): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor,$firstUnread) {
            $repo=new \CajeerEngine\Forum\Repository\DiscussionManagementRepository($this->db);
            $access=$this->access($actor);$thread=$this->threads->find($id);
            if ($thread===null) {
                $redirect=$repo->findRedirect($id)??throw new ForumException('Тема не найдена.',404);
                NodePermissionService::assert($access,$redirect['source_board_id'],'thread:read');
                $id=$redirect['target_id'];$thread=$this->threads->find($id)??throw new ForumException('Тема не найдена.',404);
            }
            NodePermissionService::assert($access,$thread['board_id'],'thread:read');$thread['tags']=$repo->tags($id);
            $board=$this->boards->view($thread['board_id'])??throw new ForumException('Раздел не найден.',404);
            $viewer=$actor?->userId??'';
            $state=$viewer===''?null:$this->tx->readState()->summary($viewer,$id);
            // Resolve the destination and fetch that page in one ACL-protected snapshot.
            if($firstUnread) $offset=25*(($state['first_unread']['page']??1)-1);
            $result=['thread'=>$thread,'breadcrumbs'=>$this->breadcrumbs($board,$access),'board'=>$this->decorated($board,$access),'posts'=>$this->posts->inThread($id,$offset,$viewer),'first_post_id'=>$this->posts->firstId($id),'page'=>1+intdiv($offset,25)];
            if ($state!==null) {
                $result['read_state']=$state;
                foreach($result['posts'] as &$post) $post['is_unread']=(int)$post['read_sequence']>$result['read_state']['read_sequence'];
                unset($post);
            }
            return $result;
        });
    }
    public function search(string $term,int $offset,?ForumAccessPolicy $actor=null,?string $tag=null): array { return $this->db->readSnapshot(fn()=> $this->tx->readState()->decorateThreads($this->threads->search($term,$offset,NodePermissionService::allowed($this->access($actor)),$tag),$actor?->userId??'')); }
    public function latest(int $offset,?ForumAccessPolicy $actor=null): array { return $this->db->readSnapshot(fn()=> $this->tx->readState()->decorateThreads($this->threads->latest($offset,NodePermissionService::allowed($this->access($actor)),$actor?->userId??''),$actor?->userId??'')); }
    public function profiles(): MemberProfileService { return new MemberProfileService($this->tx,new \CajeerEngine\Forum\Repository\ProfileRepository($this->db)); }
    public function profile(string $id,int $offset,?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $member=$this->profiles()->publicProfile($id,$actor??new ForumAccessPolicy('',[]));
            return ['member'=>$member,'threads'=>$member['activity_visible']?$this->tx->readState()->decorateThreads($this->threads->byAuthor($id,$offset,NodePermissionService::allowed($this->access($actor))),$actor?->userId??''):[]];
        });
    }
    public function management(): DiscussionManagementService { return new DiscussionManagementService($this->tx,$this->boards,$this->threads,$this->posts,new \CajeerEngine\Forum\Repository\DiscussionManagementRepository($this->db)); }
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
