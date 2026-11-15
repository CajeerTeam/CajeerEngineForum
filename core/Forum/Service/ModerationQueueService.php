<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Content\ForumContentRenderer as Content;
use CajeerEngine\Forum\Repository\{ModerationQueueRepository,BoardRepository,ThreadRepository,RecycleBinRepository,AttachmentRepository,ReportRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class ModerationQueueService
{
    public function __construct(private ForumTransaction $tx,private ModerationQueueRepository $repo,private BoardRepository $boards,private ThreadRepository $threads,private RecycleBinRepository $trash,private AttachmentRepository $files,private ReportRepository $reports) {}
    private function kind(string $kind): void {if(!in_array($kind,['report','submission'],true))throw new ForumException('Неизвестный тип дела.');}
    public function requires(ForumAccessPolicy $actor,string $board): bool
    {return $this->repo->required($board)&&!($this->tx->permissions()->access($actor)['boards'][$board]['thread:moderate']??false);}
    public function policy(ForumAccessPolicy $actor,string $board,bool $enabled): array
    {return $this->tx->write(function()use($actor,$board,$enabled){$this->boards->lockBoard($board)??throw new ForumException('Раздел не найден.',404);$this->tx->permissions()->requireAdmin($actor,true);$this->repo->policy($board,$enabled);$this->tx->audit('forum.review.policy',$actor->userId,['board_id'=>$board,'enabled'=>$enabled]);return ['review_required'=>$enabled];});}
    /** One transaction holds the target and the author's current principal before any submission. */
    public function submit(ForumAccessPolicy $actor,string $scope,string $target,array $p,DiscussionService $discussion): array
    {
        if(!in_array($scope,['thread','reply'],true))throw new \LogicException('Invalid review scope');
        $actor->require('forum:write');$target=V::id($target);$format=Content::format(array_key_exists('body_format',$p)?$p['body_format']:'plain');$body=Content::source($p['body']??null,$format);
        $ids=$p['attachment_ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)>10)throw new ForumException('Некорректные вложения.');$ids=array_map(V::id(...),$ids);if(count(array_unique($ids))!==count($ids))throw new ForumException('Повторяющиеся вложения.');sort($ids);
        $payload=['body'=>$body,'body_format'=>$format,'attachment_ids'=>$ids];
        if($scope==='thread'){
            $payload['title']=V::text($p['title']??null,'title',180,3);$kind=$p['kind']??'discussion';if(!in_array($kind,['discussion','poll','question'],true))throw new ForumException('Некорректный тип темы.');$payload['kind']=$kind;
            if($kind==='poll')$payload['poll']=PollQuestionService::normalize($p['poll']??null);elseif(($p['poll']??null)!==null)throw new ForumException('Опрос требует типа poll.');
        }
        return $this->tx->write(function()use($actor,$scope,$target,$payload,$discussion,$ids){
            $thread=$scope==='reply'?($this->threads->lockThread($target)??throw new ForumException('Тема не найдена.',404)):null;$board=$thread['board_id']??$target;
            $this->tx->permissions()->assertWrite($actor,$board,$scope==='thread'?'thread:create':'post:reply');$this->boards->writable($board);
            if($ids)$this->tx->sanctions()->enforce($actor->userId,'upload');
            if($thread&&(int)$thread['locked']!==0)throw new ForumException('Тема закрыта.',409);
            $staff=$this->tx->permissions()->access($actor)['boards'][$board]['thread:moderate']??false;
            $extra=($payload['title']??'').' '.($payload['poll']['question']??'').' '.implode(' ',$payload['poll']['options']??[]);
            $risk=$this->tx->antiSpam()->publication($actor->userId,$scope,$payload['body'],$payload['body_format'],$staff,$extra);
            if($risk===null&&!$this->requires($actor,$board))return $this->publish($actor,$scope,$target,$payload,$discussion,true);
            if($this->repo->countPending($actor->userId)>=25)throw new ForumException('Достигнут лимит заявок на модерацию.',409);
            $id=Uuid::v4();$now=time();$this->repo->insert(['id'=>$id,'author_id'=>$actor->userId,'board_id'=>$board,'thread_id'=>$thread['id']??null,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'created_at'=>$now]);
            $bytes=0;foreach($ids as $file){$a=$this->files->lockItem($file);if(!$a||$a['owner_id']!==$actor->userId||$a['post_id']!==null||$a['message_id']!==null||(int)$a['expires_at']<=$now||$this->repo->held($file))throw new ForumException('Вложение недоступно или уже ожидает проверки.',409);$bytes+=(int)$a['size_bytes'];$this->repo->hold($id,$file,$now+2592000);}
            if($bytes>AttachmentService::limits()['message_bytes'])throw new ForumException('Превышен лимит вложений.');
            $this->repo->event('submission',$id,$actor->userId,'submit',$risk===null?'':'antispam: '.$risk);$this->tx->audit('forum.review.submit',$actor->userId,['submission_id'=>$id,'board_id'=>$board]);
            return ['id'=>$id,'pending_review'=>true,'submission_id'=>$id];
        });
    }
    private function publish(ForumAccessPolicy $author,string $scope,string $target,array $p,DiscussionService $discussion,bool $approved=false): array
    {
        if($scope==='thread')return ['id'=>$discussion->createThread($author,$target,$p['title'],$p['body'],$p['body_format'],$p['attachment_ids'],$p['kind'],$p['poll']??null,$approved)];
        $id=$discussion->reply($author,$target,$p['body'],$p['body_format'],$p['attachment_ids'],$approved);return ['id'=>$id,'thread_id'=>$target,'page'=>$this->threads->pageForPost($target,$id)];
    }
    private function boardsFor(ForumAccessPolicy $actor): array
    {$this->tx->permissions()->requireCurrent($actor,'forum:moderate');$access=$this->tx->permissions()->access($actor);return array_values(array_intersect(NodePermissionService::allowed($access,'thread:moderate'),NodePermissionService::allowed($access,'thread:read')));}
    private function publicRow(string $kind,array $r,bool $owner=false): array
    {
        $r['revision_number']=(int)$r['revision_number'];$r['priority']=(int)$r['priority'];if(isset($r['related_open']))$r['related_open']=(int)$r['related_open'];foreach(['created_at','updated_at','resolved_at'] as $k)if(isset($r[$k])&&($kind==='submission'||$k==='resolved_at'))$r[$k]=(int)$r[$k];
        if($kind==='submission'){$r['payload']=json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR);if($owner)unset($r['assigned_to'],$r['priority'],$r['decided_by'],$r['current_board_id']);}
        return $r;
    }
    public function listing(ForumAccessPolicy $actor,string $kind,string $status,int $offset,?string $assigned=null): array
    {
        $this->kind($kind);if(!in_array($status,$kind==='report'?['all','open','resolved']:['all','pending','approved','rejected','cancelled'],true))throw new ForumException('Некорректный статус.');
        if($assigned!==null&&$assigned!=='unassigned')$assigned=$assigned==='me'?$actor->userId:V::id($assigned);
        return $this->tx->read(fn()=>array_map(fn($r)=>$this->publicRow($kind,$r),$this->repo->list($kind,$this->boardsFor($actor),$status,$offset,$assigned)));
    }
    public function mine(ForumAccessPolicy $actor,int $offset): array
    {return $this->tx->read(function()use($actor,$offset){$this->tx->permissions()->requireCurrent($actor,'forum:write');return array_map(fn($r)=>$this->publicRow('submission',$r,true),$this->repo->own($actor->userId,$offset));});}
    public function detail(ForumAccessPolicy $actor,string $kind,string $id,int $offset): array
    {
        $this->kind($kind);$id=V::id($id);return $this->tx->read(function()use($actor,$kind,$id,$offset){$r=($kind==='report'?$this->repo->report($id):$this->repo->submission($id))??throw new ForumException('Дело не найдено.',404);if(!in_array($r['current_board_id'],$this->boardsFor($actor),true))throw new ForumException('Дело не найдено.',404);return ['item'=>$this->publicRow($kind,$r),'history'=>$this->repo->history($kind,$id,$offset)];});
    }
    /** Resolve the current physical thread first, including a deleted target. */
    private function lockCase(ForumAccessPolicy $actor,string $kind,string $id,bool $owner=false): array
    {
        $hint=($kind==='report'?$this->repo->report($id):$this->repo->submission($id))??throw new ForumException('Дело не найдено.',404);
        $thread=$kind==='report'?$this->threads->lockForReport($id):($hint['thread_id']!==null?$this->trash->lockTarget('thread',$hint['thread_id']):null);
        if(($kind==='report'||$hint['thread_id']!==null)&&!$thread)throw new ForumException('Дело не найдено.',404);
        $board=$thread['board_id']??$hint['board_id'];$this->boards->lockBoard($board)??throw new ForumException('Раздел не найден.',404);
        $this->tx->permissions()->requireCurrent($actor,$owner?'forum:write':'forum:moderate',true);
        if(!$owner){$access=$this->tx->permissions()->access($actor);NodePermissionService::assert($access,$board,'thread:read');NodePermissionService::assert($access,$board,'thread:moderate');}
        $r=($kind==='report'?$this->reports->lockReport($id):$this->repo->lockSubmission($id))??throw new ForumException('Дело не найдено.',404);
        if($owner&&$r['author_id']!==$actor->userId)throw new ForumException('Дело не найдено.',404);
        return [$r,$board];
    }
    public function action(ForumAccessPolicy $actor,string $kind,string $id,array $input,DiscussionService $discussion,bool $owner=false): array
    {
        $this->kind($kind);$id=V::id($id);$action=$input['action']??null;if(isset($input['sanction'])&&($kind!=='report'||$action!=='resolve'||$owner))throw new ForumException('Санкция допустима только при решении по жалобе.');$expected=DraftService::revision($input['revision_number']??null);
        if(!in_array($action,$owner?['cancel']:($kind==='report'?['assign','priority','note','resolve']:['assign','priority','note','approve','reject']),true))throw new ForumException('Некорректное действие.');
        return $this->tx->write(function()use($actor,$kind,$id,$input,$discussion,$owner,$action,$expected){
            [$r,$board]=$this->lockCase($actor,$kind,$id,$owner);
            if((int)$r['revision_number']!==$expected||$r['status']!==($kind==='report'?'open':'pending'))throw new ForumException('Дело изменено или закрыто.',409);
            $reason=V::text($input['reason']??'','reason',500,in_array($action,['note','resolve','reject'],true)?1:0);$value=$reason;
            if($action==='assign'){
                $value=$input['assigned_to']??null;if($value!==null){$value=V::id($value);$assignee=new ForumAccessPolicy($value,['*']);$this->tx->permissions()->requireCurrent($assignee,'forum:moderate');$access=$this->tx->permissions()->access($assignee);NodePermissionService::assert($access,$board,'thread:read');NodePermissionService::assert($access,$board,'thread:moderate');}
            }elseif($action==='priority'){$value=$input['priority']??null;if(!is_int($value)||$value<0||$value>2)throw new ForumException('Некорректный приоритет.');}
            elseif($action==='resolve'){
                $disposition=$input['disposition']??'resolved';if(!in_array($disposition,['resolved','dismissed','deleted'],true))throw new ForumException('Некорректное решение.');
                if($disposition==='deleted'){
                    $post=$this->trash->lockTarget('post',$r['post_id'])??throw new ForumException('Сообщение не найдено.',404);
                    if(!$post['parent_deleted']&&$post['deleted_at']===null){$isFirst=$this->trash->first($post['thread_id'])===$r['post_id'];$this->tx->recycle()->change($actor,$isFirst?'thread':'post',$isFirst?$post['thread_id']:$r['post_id'],'delete',$reason);}
                }
                $sanction=$input['sanction']??null;
                if($sanction!==null){
                    if(!is_array($sanction)||$disposition==='dismissed')throw new ForumException('Некорректная санкция по жалобе.');
                    $post=$this->trash->lockTarget('post',$r['post_id'])??throw new ForumException('Сообщение не найдено.',404);
                    if($post['author_id']===null)throw new ForumException('Автор недоступен.',409);
                    $issued=$this->tx->sanctions()->issue($actor,$post['author_id'],['board_id'=>$board,'reason'=>$reason]+$sanction);
                    $this->reports->linkSanction($id,$issued['id']);
                    $this->tx->audit('forum.report.sanction',$actor->userId,['report_id'=>$id,'sanction_id'=>$issued['id']]);
                }
                $value=['reason'=>$reason,'disposition'=>$disposition];
            }elseif($action==='approve'){
                $author=new ForumAccessPolicy($r['author_id'],['forum:write']);$p=json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR);
                $this->tx->permissions()->requireCurrent($author,'forum:write',true);
                // Held bytes cannot be consumed elsewhere; lock in UUID order before releasing the reservation.
                foreach($p['attachment_ids'] as $file){$a=$this->files->lockItem($file);if(!$a||$a['owner_id']!==$r['author_id']||$a['post_id']!==null||$a['message_id']!==null||!$this->repo->heldBy($file,$id,true))throw new ForumException('Вложение заявки недоступно.',409);$this->repo->renew($file,time()+86400);}
                $this->repo->release($id);
                $result=$this->publish($author,$r['thread_id']===null?'thread':'reply',$r['thread_id']??$board,$p,$discussion,true);$value=['reason'=>$reason,'id'=>$result['id']];
            }elseif(in_array($action,['reject','cancel'],true))$this->repo->release($id);
            if(!$this->repo->update($kind,$id,$expected,$action,$value,$actor->userId))throw new ForumException('Дело изменено.',409);
            $detail=match($action){'assign'=>$value??'unassigned','priority'=>(string)$value,'resolve'=>$value['disposition'].': '.$reason,default=>$reason};
            $this->repo->event($kind,$id,$actor->userId,$action,mb_substr($detail,0,500),$expected+1);$this->tx->audit('forum.moderation.'.$action,$actor->userId,['kind'=>$kind,'case_id'=>$id,'board_id'=>$board]);
            return $this->publicRow($kind,$kind==='report'?$this->repo->report($id):$this->repo->submission($id),$owner);
        });
    }
    public function download(ForumAccessPolicy $actor,string $id,string $file): array
    {return $this->tx->read(function()use($actor,$id,$file){$r=$this->repo->submission($id)??throw new ForumException('Дело не найдено.',404);if(!in_array($r['current_board_id'],$this->boardsFor($actor),true)||$r['status']!=='pending'||!$this->repo->heldBy($file,$id))throw new ForumException('Файл не найден.',404);$a=$this->files->find($file)??throw new ForumException('Файл не найден.',404);if($a['owner_id']!==$r['author_id']||$a['post_id']!==null||$a['message_id']!==null)throw new ForumException('Файл не найден.',404);$bytes=$this->files->bytes($file);$data=base64_decode($bytes['file_data']??'',true);if($data===false||strlen($data)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash('sha256',$data)))throw new ForumException('Файл повреждён.',503);return $a+['data'=>$data];});}
    public function reportEvent(string $id,string $actor,string $action,string $detail='',int $version=1): void {$this->repo->event('report',$id,$actor,$action,$detail,$version);}
}
