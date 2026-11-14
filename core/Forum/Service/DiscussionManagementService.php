<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{BoardRepository,ThreadRepository,PostRepository,DiscussionManagementRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class DiscussionManagementService
{
    public function __construct(private ForumTransaction $tx,private BoardRepository $boards,private ThreadRepository $threads,private PostRepository $posts,private DiscussionManagementRepository $repo) {}

    private static function ids(mixed $value,int $max=100): array
    {
        if (!is_array($value)||!array_is_list($value)||count($value)<1||count($value)>$max) throw new ForumException('Некорректный список идентификаторов.');
        $ids=array_map(V::id(...),$value);
        if (count(array_unique($ids))!==count($ids)) throw new ForumException('Повторяющийся идентификатор.');
        return $ids;
    }
    public static function normalizeMetadata(mixed $prefix,mixed $tags): array
    {
        $prefix=V::text($prefix,'prefix',60,0);
        if (!is_array($tags)||!array_is_list($tags)||count($tags)>10) throw new ForumException('Не более 10 тегов.');
        $normalized=[];
        foreach ($tags as $tag) {
            $tag=mb_strtolower(V::text($tag,'tag',60));
            $tag=V::text($tag,'tag',60);
            if (preg_match('/[\p{C},]/u',$tag)) throw new ForumException('Некорректный тег.');
            $normalized[]=$tag;
        }
        if (preg_match('/\p{C}/u',$prefix)) throw new ForumException('Некорректный префикс.');
        return [$prefix,array_values(array_unique($normalized))];
    }
    /** Lock every involved thread, then every board in deterministic order. ACL and topology
     * changes lock boards too. Never move posts while a writer holds their old thread lock. */
    private function lock(ForumAccessPolicy $actor,array $ids,?string $destination=null): array
    {
        $actor->require('forum:moderate');
        sort($ids,SORT_STRING);$threads=[];$boards=[];
        foreach ($ids as $id) {
            $thread=$this->threads->lockThread($id)??throw new ForumException('Тема не найдена.',404);
            $threads[$id]=$thread;$boards[]=$thread['board_id'];
        }
        if ($destination!==null) $boards[]=$destination;
        $boards=array_unique($boards);sort($boards,SORT_STRING);
        foreach ($boards as $board) $this->boards->lockBoard($board)??throw new ForumException('Раздел не найден.',404);
        $access=$this->tx->permissions()->access($actor);
        foreach ($boards as $board) NodePermissionService::assert($access,$board,'thread:moderate');
        return $threads;
    }
    public function metadata(ForumAccessPolicy $actor,string $id,mixed $prefix,mixed $tags): array
    {
        $id=V::id($id);[$prefix,$tags]=self::normalizeMetadata($prefix,$tags);
        return $this->tx->write(function()use($actor,$id,$prefix,$tags){
            $this->lock($actor,[$id]);$this->repo->metadata($id,$prefix,$tags);
            $this->tx->audit('forum.thread.metadata',$actor->userId,['thread_id'=>$id,'prefix'=>$prefix,'tags'=>$tags]);
            return ['id'=>$id,'prefix'=>$prefix,'tags'=>$tags];
        });
    }
    public function bulk(ForumAccessPolicy $actor,mixed $ids,mixed $action,mixed $destination=null): array
    {
        $ids=self::ids($ids);if (!in_array($action,['lock','unlock','pin','unpin','move'],true)) throw new ForumException('Неизвестное массовое действие.');
        $board=$action==='move'?V::id($destination):null;
        return $this->tx->write(function()use($actor,$ids,$action,$board){
            $threads=$this->lock($actor,$ids,$board);
            if ($board!==null) $this->boards->writable($board);
            foreach ($threads as $id=>$thread) {
                if ($board!==null) $this->repo->moveThread($id,$board); else $this->threads->moderate($id,$action);
            }
            $this->tx->audit('forum.thread.bulk',$actor->userId,['thread_ids'=>$ids,'action'=>$action,'board_id'=>$board]);
            return ['ids'=>$ids];
        });
    }
    public function merge(ForumAccessPolicy $actor,string $target,mixed $sources): array
    {
        $target=V::id($target);$sources=self::ids($sources,20);
        if (in_array($target,$sources,true)) throw new ForumException('Нельзя объединить тему с собой.');
        return $this->tx->write(function()use($actor,$target,$sources){
            $threads=$this->lock($actor,[$target,...$sources]);$this->boards->writable($threads[$target]['board_id']);
            // Keep target title/prefix and combine tags without silently dropping metadata.
            $tags=$this->repo->tags($target);
            foreach ($sources as $source) $tags=array_values(array_unique([...$tags,...$this->repo->tags($source)]));
            if (count($tags)>10) throw new ForumException('После объединения получится больше 10 тегов. Уменьшите их число.',409);
            $this->repo->metadata($target,$threads[$target]['prefix'],$tags);
            foreach ($sources as $source) {
                $this->repo->appendAll($source,$target,$this->posts->nextOrdinal($target)-1);
                $this->repo->mergeWatches($source,$target);
                $this->repo->redirect($source,$threads[$source]['board_id'],$target);
                $this->threads->moderate($source,'delete');
            }
            $this->threads->touch($target);
            $this->tx->audit('forum.thread.merge',$actor->userId,['target_id'=>$target,'source_ids'=>$sources]);
            return ['id'=>$target];
        });
    }
    /** Split or move selected replies; keep the first post and at least one post in source. */
    public function transfer(ForumAccessPolicy $actor,string $source,mixed $selected,mixed $target,mixed $destination,mixed $title): array
    {
        $source=V::id($source);$selected=self::ids($selected,500);
        $target=$target===null?null:V::id($target);
        if ($target===$source) throw new ForumException('Выберите другую тему.');
        $board=$target===null?V::id($destination):null;
        $title=$target===null?V::text($title,'title',180,3):'';
        return $this->tx->write(function()use($actor,$source,$selected,$target,$board,$title){
            $threads=$this->lock($actor,$target===null?[$source]:[$source,$target],$board);
            $destination=$target===null?$board:$threads[$target]['board_id'];
            if ($destination===null) throw new \LogicException('Missing transfer destination');
            $this->boards->writable($destination);
            $chosen=$this->repo->selectedPosts($source,$selected);
            if (count($chosen)!==count($selected)||in_array($this->posts->firstId($source),$selected,true)) throw new ForumException('Выберите ответы из исходной темы, кроме первого сообщения.',409);
            if ($target===null) {
                $target=Uuid::v4();$this->threads->insert($target,$destination,$actor->userId,$title);
                $this->repo->setFirst($target,$chosen[0]);
                $this->repo->metadata($target,$threads[$source]['prefix'],$this->repo->tags($source));
            }
            $ordinal=$this->posts->nextOrdinal($target);
            foreach ($chosen as $post) $this->repo->movePost($post['id'],$source,$target,$ordinal++);
            $this->threads->touch($source);$this->threads->touch($target);
            $this->tx->audit($board===null?'forum.post.move':'forum.thread.split',$actor->userId,['source_id'=>$source,'target_id'=>$target,'post_ids'=>$selected]);
            return ['id'=>$target];
        });
    }
}
