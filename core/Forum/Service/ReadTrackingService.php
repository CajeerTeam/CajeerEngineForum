<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{ReadStateRepository,BoardRepository,ThreadRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class ReadTrackingService
{
    public function __construct(private ForumTransaction $tx,private ReadStateRepository $repo,private BoardRepository $boards,private ThreadRepository $threads) {}

    public function unread(ForumAccessPolicy $actor,int $offset): array
    {
        $this->authenticated($actor);
        return $this->tx->read(fn()=> $this->repo->unread($actor->userId,NodePermissionService::allowed($this->tx->permissions()->access($actor)),$offset));
    }
    private function authenticated(ForumAccessPolicy $actor): void
    { if ($actor->userId==='') throw new ForumException('Требуется авторизация.',401); }

    /** Accessible subtree only. A hidden child never contributes to its parent's count. */
    public static function subtree(array $boards,string $root): array
    {
        $children=[];
        foreach($boards as $b) $children[$b['parent_id']??''][]=$b['id'];
        $ids=[];$pending=[$root];
        while($pending) {
            $id=array_pop($pending);
            if(isset($ids[$id])) continue;
            $ids[$id]=true;
            foreach($children[$id]??[] as $child) $pending[]=$child;
        }
        return array_keys($ids);
    }
    private function allowedBoards(array $access,?string $board): array
    {
        $allowed=NodePermissionService::allowed($access);
        if ($board===null) return $allowed;
        NodePermissionService::assert($access,$board,'thread:read');
        return array_values(array_intersect($allowed,self::subtree($this->boards->topology(),$board)));
    }
    /** Only the authenticated user's state can be changed. GET requests never mutate state. */
    public function mark(ForumAccessPolicy $actor,array $input): array
    {
        $this->authenticated($actor);
        $scope=$input['scope']??null;
        if(!in_array($scope,['thread','board','all'],true)) throw new ForumException('Некорректный scope прочтения.',422);
        $id=$scope==='all'?null:V::id($input['id']??null);
        $post=$input['post_id']??null;$sequence=$input['sequence']??null;
        if ($post!==null || $sequence!==null) {
            if ($scope!=='thread' || $post===null || !is_int($sequence) || $sequence<1) throw new ForumException('Требуется сообщение и номер публикации.',422);
            $post=V::id($post);
        }
        if ($scope==='thread') return $this->tx->write(function()use($actor,$id,$post,$sequence){
            $thread=$this->threads->lockThread($id)??throw new ForumException('Тема не найдена.',404);
            $this->boards->lockBoard($thread['board_id'])??throw new ForumException('Тема не найдена.',404);
            NodePermissionService::assert($this->tx->permissions()->access($actor,lockActor:true),$thread['board_id'],'thread:read');
            $cursor=(int)$thread['read_sequence'];
            if($post!==null) {
                $actual=$this->repo->post($id,$post);
                if($actual===null || (int)$actual['read_sequence']!==$sequence) throw new ForumException('Сообщение перемещено. Обновите страницу.',409);
                $cursor=$sequence;
            }
            $this->repo->mark($actor->userId,$id,$cursor);
            return ['scope'=>'thread','id'=>$id]+$this->repo->summary($actor->userId,$id);
        });
        // Capture candidate IDs before locks; lock threads in the same order as merge/bulk,
        // then topology and actor. New topics published after the snapshot stay unread.
        $candidates=$this->tx->read(fn()=> $this->repo->candidateThreads($this->allowedBoards($this->tx->permissions()->access($actor),$id)));
        return $this->tx->write(function()use($actor,$scope,$id,$candidates){
            // SQLite takes its writer reservation first. Other DBs lock threads before
            // the tree. The candidate read is outside this transaction so MySQL's
            // repeatable-read snapshot cannot reuse stale ACLs after acquiring locks.
            $sqlite=$this->tx->driver()==='sqlite';
            if($sqlite) $this->boards->lockTree();
            $locked=$this->repo->lockCandidates($candidates);
            if(!$sqlite) $this->boards->lockTree();
            $allowed=array_fill_keys($this->allowedBoards($this->tx->permissions()->access($actor,lockActor:true),$id),true);
            $ids=array_keys(array_filter($locked,fn($board)=>isset($allowed[$board])));
            $this->repo->markThreads($actor->userId,$ids);
            return ['scope'=>$scope,'id'=>$id,'marked_threads'=>count($ids)];
        });
    }
}
