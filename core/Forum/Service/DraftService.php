<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Content\ForumContentRenderer as Content;
use CajeerEngine\Forum\Repository\{DraftRepository,AttachmentRepository,BoardRepository,ThreadRepository,ConversationRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class DraftService
{
    public function __construct(private ForumTransaction $tx,private DraftRepository $repo,private AttachmentRepository $files,private BoardRepository $boards,private ThreadRepository $threads,private ConversationRepository $conversations) {}
    public static function revision(mixed $value,bool $zero=false): int
    {if(!is_int($value)||$value<($zero?0:1)||$value>2147483646)throw new ForumException('Некорректная версия.',422);return $value;}
    public static function reason(mixed $value): string {return V::text($value??'','edit_reason',300,0);}
    /** A lost HTTP response may be retried, but only for the identical next version. */
    public static function isSaveReplay(array $row,int $expected,string $payload): bool
    {return $row['result_json']===null&&(int)$row['revision_number']===$expected+1&&$row['payload']===$payload;}
    private function publicRow(array $row): array
    {return ['id'=>$row['id'],'scope'=>$row['scope'],'target_id'=>$row['target_id'],'revision_number'=>(int)$row['revision_number'],'payload'=>(object)json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'result'=>$row['result_json']===null?null:json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR),'updated_at'=>(int)$row['updated_at'],'expires_at'=>(int)$row['expires_at']];}
    private function active(?array $row): array
    {if(!$row||(int)$row['expires_at']<=time())throw new ForumException('Черновик не найден или истёк.',404);return $row;}
    private function context(ForumAccessPolicy $actor,string $scope,?string $target): void
    {
        if($scope==='new_conversation')return;
        if($scope==='conversation'){$this->conversations->membership($target,$actor->userId);return;}
        $board=$scope==='thread'?$target:($this->threads->find($target)??throw new ForumException('Тема не найдена.',404))['board_id'];
        NodePermissionService::assert($this->tx->permissions()->access($actor),$board,$scope==='thread'?'thread:create':'post:reply');
    }
    private function payload(mixed $value,string $scope): array
    {
        if(!is_array($value))throw new ForumException('Некорректный черновик.',422);
        $format=Content::format($value['body_format']??'plain');$body=Content::source($value['body']??'',$format,true);
        $ids=$value['attachment_ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)>10)throw new ForumException('Некорректные вложения.',422);
        $ids=array_map(V::id(...),$ids);if(count(array_unique($ids))!==count($ids))throw new ForumException('Повторяющиеся вложения.',422);
        $p=['body'=>$body,'body_format'=>$format,'attachment_ids'=>$ids];
        if(in_array($scope,['thread','new_conversation'],true))$p['title']=V::text($value['title']??'','title',180,0);
        if($scope==='thread'){
            $kind=$value['kind']??'discussion';if(!in_array($kind,['discussion','poll','question'],true))throw new ForumException('Некорректный тип темы.',422);$p['kind']=$kind;
            if($kind==='poll'){
                $poll=$value['poll']??[];if(!is_array($poll))throw new ForumException('Некорректный опрос.',422);
                $options=$poll['options']??[];if(!is_array($options)||!array_is_list($options)||count($options)>20)throw new ForumException('Некорректные варианты.',422);
                $p['poll']=['question'=>V::text($poll['question']??'','question',180,0),'options'=>array_map(fn($x)=>V::text($x,'option',180,0),$options),'max_choices'=>max(1,min(20,(int)($poll['max_choices']??1))),'allow_change'=>($poll['allow_change']??true)===true,'visibility'=>in_array($poll['visibility']??null,['always','voted','closed'],true)?$poll['visibility']:'always','closes_at'=>is_int($poll['closes_at']??null)?$poll['closes_at']:null];
            }
        }
        if($scope==='new_conversation'){
            $kind=$value['kind']??'direct';if(!in_array($kind,['direct','group'],true))throw new ForumException('Некорректный тип переписки.',422);$p['kind']=$kind;
            $participants=$value['participant_ids']??[];if(!is_array($participants)||!array_is_list($participants)||count($participants)>19)throw new ForumException('Некорректные получатели.',422);$p['participant_ids']=array_map(V::id(...),$participants);
        }
        return $p;
    }
    public function list(ForumAccessPolicy $actor,int $offset,mixed $scope=null,mixed $target=null): array
    {
        if($scope!==null){if(!in_array($scope,['thread','reply','conversation','new_conversation'],true))throw new ForumException('Некорректный тип черновика.',422);$target=$scope==='new_conversation'?null:V::id($target);}
        else $target=null;
        return $this->tx->read(function()use($actor,$offset,$scope,$target){$this->tx->permissions()->requireCurrent($actor,'forum:write');return array_map($this->publicRow(...),$this->repo->list($actor->userId,$offset,$scope,$target));});
    }
    public function get(ForumAccessPolicy $actor,string $id): array
    {return $this->tx->read(function()use($actor,$id){$this->tx->permissions()->requireCurrent($actor,'forum:write');return $this->publicRow($this->active($this->repo->find($id,$actor->userId)));});}
    public function save(ForumAccessPolicy $actor,string $id,array $input): array
    {
        $expected=self::revision($input['revision_number']??null,true);$scope=$input['scope']??null;if(!in_array($scope,['thread','reply','conversation','new_conversation'],true))throw new ForumException('Некорректный тип черновика.',422);
        $target=$scope==='new_conversation'?null:V::id($input['target_id']??null);$payload=$this->payload($input['payload']??null,$scope);
        return $this->tx->write(function()use($actor,$id,$expected,$scope,$target,$payload){
            $user=$actor->userId;$row=$this->repo->lockDraft($id,$user);$this->tx->permissions()->requireCurrent($actor,'forum:write',true);
            // Recheck after the user lock for concurrent first saves with the same client ID.
            if(!$row)$row=$this->repo->lockDraft($id,$user);
            $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $replay=$row&&self::isSaveReplay($row,$expected,$json);
            if($row&&($row['result_json']!==null||$row['scope']!==$scope||$row['target_id']!==$target||(!$replay&&(int)$row['revision_number']!==$expected)||(int)$row['expires_at']<=time()))throw new ForumException('Черновик изменён или опубликован. Обновите его.',409);
            if(!$row&&$expected!==0)throw new ForumException('Черновик не найден.',404);
            if(!$row&&$this->repo->countActive($user)>=100)throw new ForumException('Достигнут лимит черновиков.',409);
            $this->context($actor,$scope,$target);$now=time();$expires=$replay?(int)$row['expires_at']:$now+2592000;$ids=$payload['attachment_ids'];sort($ids);
            foreach($ids as $file){$a=$this->files->lockItem($file);if(!$a||$a['owner_id']!==$user||$a['post_id']!==null||$a['message_id']!==null||(int)$a['expires_at']<=$now||$this->files->held($file))throw new ForumException('Временное вложение недоступно.',404);$this->repo->extendAttachment($file,$user,$expires);}
            if($replay)return $this->publicRow($row);
            if(!$row)$this->repo->insert(['id'=>$id,'user_id'=>$user,'scope'=>$scope,'target_id'=>$target,'payload'=>$json,'updated_at'=>$now,'expires_at'=>$expires]);
            elseif(!$this->repo->save($id,$user,$expected,$json,$now,$expires))throw new ForumException('Черновик изменён.',409);
            return $this->publicRow($this->repo->find($id,$user));
        });
    }
    public function delete(ForumAccessPolicy $actor,string $id,int $expected): array
    {return $this->tx->write(function()use($actor,$id,$expected){$this->repo->lockDraft($id,$actor->userId);$this->tx->permissions()->requireCurrent($actor,'forum:write',true);if(!$this->repo->delete($id,$actor->userId,$expected))throw new ForumException('Черновик изменён или не найден.',409);return ['deleted'=>true];});}
    public function publish(ForumAccessPolicy $actor,string $id,int $expected,DiscussionService $discussions): array
    {
        return $this->tx->write(function()use($actor,$id,$expected,$discussions){
            $row=$this->active($this->repo->lockDraft($id,$actor->userId));$this->tx->permissions()->requireCurrent($actor,'forum:write');
            if((int)$row['revision_number']!==$expected)throw new ForumException('Черновик изменён. Обновите его.',409);
            if($row['result_json']!==null)return json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
            $p=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);$target=$row['target_id'];$scope=$row['scope'];
            $result=match($scope){
                'thread','reply'=>$this->tx->moderationQueue()->submit($actor,$scope,$target,$p,$discussions),
                'conversation'=>$this->tx->conversations()->send($actor,$target,$p)+['conversation_id'=>$target],
                'new_conversation'=>$this->tx->conversations()->create($actor,$p),
                default=>throw new \LogicException('Invalid draft scope')};
            if(!$this->repo->complete($id,$actor->userId,$expected,json_encode($result,JSON_THROW_ON_ERROR),time()))throw new ForumException('Черновик изменён.',409);
            return $result;
        });
    }
    public function cleanup(): int {return $this->tx->write(fn()=>$this->repo->cleanup(time()));}
}
