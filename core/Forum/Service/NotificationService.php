<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\NotificationRepository;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class NotificationService
{
    public function __construct(private ForumTransaction $tx,private NotificationRepository $repo) {}

    /** Explicit stable UUID references, not ambiguous display names. Limit fanout from text. */
    public static function references(string $body,string $prefix): array
    {
        preg_match_all('/'.preg_quote($prefix,'/').'\{([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\}/D',$body,$matches);
        return array_slice(array_values(array_unique($matches[1])),0,20);
    }
    public function published(string $actor,string $thread,string $post,string $body,bool $reply): void
    {
        $this->tx->watches()->published($actor,$thread,$post,$reply);
        if($reply)$this->repo->reply($actor,$post,$thread);
        $this->repo->enqueue('mention',$actor,$post,self::references($body,'@'));
        $authors=[];
        foreach(self::references($body,'>>') as $quote){$author=$this->repo->quotedAuthor($quote,$thread);if($author!==null)$authors[]=$author;}
        $this->repo->enqueue('quote',$actor,$post,$authors);
    }
    public function reaction(string $actor,string $post,bool $enabled): void
    {
        if(!$enabled){$this->repo->removeReaction($actor,$post);return;}
        $author=$this->repo->postAuthor($post);
        if($author!==null)$this->repo->enqueue('reaction',$actor,$post,[$author]);
    }
    public function moderated(string $actor,array $thread,string $action): void
    {
        $post=$this->repo->firstPost($thread['id']);
        if($post===null||$thread['author_id']===null)return;
        $changed=match($action){'lock'=>(int)$thread['locked']===0,'unlock'=>(int)$thread['locked']===1,'pin'=>(int)$thread['pinned']===0,'unpin'=>(int)$thread['pinned']===1,default=>false};
        if($changed)$this->repo->enqueue('moderation',$actor,$post,[$thread['author_id']],$action);
    }
    /** Bounded durable outbox drain. Lock and promote atomically; crash leaves pending rows. */
    public function dispatch(int $limit=100): int
    {
        $done=0;
        foreach($this->repo->pending($limit) as $key){
            $done+=(int)$this->tx->write(function()use($key){
                $user=$key['recipient_id'];$id=$key['event_id'];$row=$this->repo->claim($id,$user);
                if($row===null)return false;
                $target=$this->repo->target($row['post_id']);$deliver=false;
                if($target!==null && $this->repo->preferences($user)[$row['kind']] && ($row['subscription_kind']===null||$this->repo->subscriptionEligible($user,$row['post_id'],$row['subscription_kind']))){
                    try{$access=$this->tx->permissions()->access(new ForumAccessPolicy($user,['*']));$deliver=in_array($target['board_id'],NodePermissionService::allowed($access),true);}
                    catch(ForumException $e){if($e->status!==401)throw $e;}
                }
                $this->repo->finish($id,$user,$deliver);return true;
            });
        }
        return $done;
    }
    public function inbox(ForumAccessPolicy $actor,int $offset,bool $countOnly=false): array
    {
        return $this->tx->read(function()use($actor,$offset,$countOnly){
            $allowed=NodePermissionService::allowed($this->tx->permissions()->access($actor));
            $result=['unread'=>$this->repo->countUnread($actor->userId,$allowed)];
            if(!$countOnly)$result+=['items'=>$this->repo->inbox($actor->userId,$allowed,$offset),'preferences'=>$this->repo->preferences($actor->userId)];
            return $result;
        });
    }
    public function target(ForumAccessPolicy $actor,string $id): array
    {
        return $this->tx->read(function()use($actor,$id){
            $allowed=NodePermissionService::allowed($this->tx->permissions()->access($actor));
            return $this->repo->inbox($actor->userId,$allowed,0,V::id($id))[0]??throw new ForumException('Уведомление не найдено.',404);
        });
    }
    public function mark(ForumAccessPolicy $actor,array $body): array
    {
        if(count($body)!==1 || (!array_key_exists('id',$body) && ($body['all']??null)!==true))throw new ForumException('Укажите id или all=true.',422);
        $id=($body['all']??false)===true?null:V::id($body['id']??null);
        return $this->tx->write(function()use($actor,$id){
            $allowed=NodePermissionService::allowed($this->tx->permissions()->access($actor,lockActor:true));
            if(!$this->repo->mark($actor->userId,$allowed,$id))throw new ForumException('Уведомление не найдено.',404);
            return ['unread'=>$this->repo->countUnread($actor->userId,$allowed)];
        });
    }
    public function preferences(ForumAccessPolicy $actor,array $body): array
    {
        if(count($body)!==count(NotificationRepository::KINDS))throw new ForumException('Укажите все типы уведомлений.',422);
        foreach(NotificationRepository::KINDS as $kind)if(!is_bool($body[$kind]??null))throw new ForumException('Некорректные настройки уведомлений.',422);
        return $this->tx->write(function()use($actor,$body){$this->tx->permissions()->access($actor,lockActor:true);$this->repo->savePreferences($actor->userId,$body);return ['preferences'=>$this->repo->preferences($actor->userId)];});
    }
}
