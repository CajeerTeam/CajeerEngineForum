<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\ConversationRepository;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class ConversationService
{
    public function __construct(private ForumTransaction $tx,private ConversationRepository $repo) {}
    private function read(ForumAccessPolicy $actor,callable $fn): mixed
    { return $this->tx->read(function()use($actor,$fn){$this->tx->permissions()->requireCurrent($actor,'forum:write');return $fn();}); }
    private function write(ForumAccessPolicy $actor,callable $fn,bool $admin=false): mixed
    { return $this->tx->write(function()use($actor,$fn,$admin){$this->repo->lockWrites();$this->tx->permissions()->requireCurrent($actor,$admin?'*':'forum:write',true);return $fn();}); }
    private function recipients(mixed $value,string $actor): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)<1||count($value)>19)throw new ForumException('Выберите от 1 до 19 участников.');
        $ids=array_map(V::id(...),$value);
        if(count(array_unique($ids))!==count($ids)||in_array($actor,$ids,true))throw new ForumException('Повторяющийся или собственный участник.');return $ids;
    }
    private function checkInvite(string $actor,string $target,array $members,array $trust): void
    {
        if(!$this->repo->eligibleUser($target)||!TrustService::accepts($this->repo->incoming($target),$trust['level']==='established',$this->repo->follows($target,$actor))||$this->repo->blocked($actor,$target))throw new ForumException('Приглашение этого участника недоступно.',403);
        foreach($members as $member)if($this->repo->blocked($member,$target))throw new ForumException('Приглашение этого участника недоступно.',403);
    }
    public function create(ForumAccessPolicy $actor,array $body): array
    {
        $user=$actor->userId;$ids=$this->recipients($body['participant_ids']??null,$user);$title=V::text($body['title']??null,'title',180);$format=array_key_exists('body_format',$body)?\CajeerEngine\Forum\Content\ForumContentRenderer::format($body['body_format']):null;$text=\CajeerEngine\Forum\Content\ForumContentRenderer::source($body['body']??null,$format??'plain');
        $kind=$body['kind']??(count($ids)===1?'direct':'group');if(!in_array($kind,['direct','group'],true)||($kind==='direct'&&count($ids)!==1))throw new ForumException('Некорректный тип переписки.');
        return $this->write($actor,function()use($user,$ids,$title,$text,$kind,$format,$body){
            $this->tx->sanctions()->enforce($user,'conversation');$this->tx->antiSpam()->publication($user,'private',$text,$format??'plain',false,$title);$trust=$this->tx->trust()->status($user);if($kind==='group')TrustService::assertGroup($trust,count($ids)+1);$this->repo->limit($user,'create',3600,$trust['new_conversations_hour']);$members=[$user];
            foreach($ids as $target){$this->repo->limit($user,'invite',3600,$trust['invitations_hour']);$this->checkInvite($user,$target,$members,$trust);$members[]=$target;}
            $id=$this->repo->create($user,$title,$kind);foreach($members as $member)$this->repo->join($id,$member,1);
            $msg=$this->repo->append($id,$user,$text,1,$format??'plain');$this->tx->revisions()->initial('message',$msg,$text,$format??'plain',$user);$this->tx->attachments()->attach($user,'message',$msg,$body['attachment_ids']??[]);$this->repo->mark($id,$user,1);return ['id'=>$id,'message_id'=>$msg];
        });
    }
    public function inbox(ForumAccessPolicy $actor,int $offset,bool $archived,bool $notifications,mixed $search): array
    {
        $q=V::text($search??'','q',100,0);return $this->read($actor,function()use($actor,$offset,$archived,$notifications,$q){$counts=$this->repo->unreadCounts($actor->userId);return ['items'=>$this->repo->inbox($actor->userId,$offset,$archived,$notifications,$q),'unread'=>$counts['conversation_total_unread'],'notification_unread'=>$counts['conversation_unread']];});
    }
    /** Conversation alerts are durable unread membership state, not copied private bodies. */
    public function alerts(ForumAccessPolicy $actor,bool $countOnly): array
    {
        try{return $this->read($actor,function()use($actor,$countOnly){$result=$this->repo->unreadCounts($actor->userId);if(!$countOnly)$result['conversations']=$this->repo->inbox($actor->userId,0,false,true);return $result;});}
        catch(ForumException $e){if($e->status!==403)throw $e;return ['conversation_unread'=>0,'conversation_total_unread'=>0]+($countOnly?[]:['conversations'=>[]]);}
    }
    private function owner(array $conversation,array $members): ?string
    { return in_array($conversation['owner_id'],$members,true)?$conversation['owner_id']:($members[0]??null); }
    public function view(ForumAccessPolicy $actor,string $id,int $offset): array
    {
        return $this->read($actor,function()use($actor,$id,$offset){$c=$this->repo->membership($id,$actor->userId);$members=$this->repo->participants($id);$others=array_diff(array_column($members,'user_id'),[$actor->userId]);$contactAllowed=$others!==[];try{$this->tx->sanctions()->enforce($actor->userId,'conversation',null,false);}catch(ForumException $e){if($e->status!==403)throw $e;$contactAllowed=false;}foreach($others as $other)if($this->repo->blocked($actor->userId,$other))$contactAllowed=false;return ['contact_allowed'=>$contactAllowed,'conversation'=>ConversationRepository::normalize($c),'participants'=>$members,'messages'=>$this->tx->attachments()->decorate('message',array_reverse($this->repo->messages($id,(int)$c['joined_sequence'],$offset))),'can_invite'=>$contactAllowed&&$c['kind']==='group'&&$this->owner($c,array_column($members,'user_id'))===$actor->userId,'can_manage_participants'=>$c['kind']==='group'&&$this->owner($c,array_column($members,'user_id'))===$actor->userId,'group_participants_max'=>$this->tx->trust()->status($actor->userId)['group_participants_max']];});
    }
    public function send(ForumAccessPolicy $actor,string $id,array $body): array
    {
        $format=array_key_exists('body_format',$body)?\CajeerEngine\Forum\Content\ForumContentRenderer::format($body['body_format']):null;$text=\CajeerEngine\Forum\Content\ForumContentRenderer::source($body['body']??null,$format??'plain');return $this->write($actor,function()use($actor,$id,$text,$format,$body){
            $user=$actor->userId;$this->tx->sanctions()->enforce($user,'conversation');$c=$this->repo->membership($id,$user);$members=array_column($this->repo->participants($id),'user_id');$others=array_diff($members,[$user]);
            if($others===[])throw new ForumException('В переписке нет других участников.',409);
            foreach($others as $target)if($this->repo->blocked($user,$target))throw new ForumException('Отправка в эту переписку недоступна.',403);
            $this->tx->antiSpam()->publication($user,'private',$text,$format??'plain');$this->repo->limit($user,'send',60,30);$message_sequence=(int)$c['last_sequence']+1;$msg=$this->repo->append($id,$user,$text,$message_sequence,$format??'plain');$this->tx->revisions()->initial('message',$msg,$text,$format??'plain',$user);
            $this->tx->attachments()->attach($user,'message',$msg,$body['attachment_ids']??[]);
            // Sending is not proof that previously received messages were read.
            return ['id'=>$msg,'message_sequence'=>$message_sequence];
        });
    }
    public function revisionHistory(ForumAccessPolicy $actor,string $id,string $msg,int $offset=0,?int $number=null): array
    {
        return $this->read($actor,function()use($actor,$id,$msg,$offset,$number){
            $c=$this->repo->membership($id,$actor->userId);$m=$this->repo->message($id,$msg,(int)$c['joined_sequence']);
            if($m['author_id']!==$actor->userId)throw new ForumException('Недостаточно прав.',403);
            return $number===null?$this->tx->revisions()->history('message',$msg,$offset):$this->tx->revisions()->version('message',$msg,$number);
        });
    }
    public function restoreRevision(ForumAccessPolicy $actor,string $id,string $msg,int $number,int $expected): array
    {
        $old=$this->revisionHistory($actor,$id,$msg,0,$number);
        return $this->edit($actor,$id,$msg,['body'=>$old['body'],'body_format'=>$old['body_format'],'expected_revision'=>$expected,'edit_reason'=>'Restore revision '.$number],true);
    }
    public function edit(ForumAccessPolicy $actor,string $id,string $msg,array $body,bool $forceRevision=false): array
    {
        $text=$body['body']??null;$format=array_key_exists('body_format',$body)?\CajeerEngine\Forum\Content\ForumContentRenderer::format($body['body_format']):null;return $this->write($actor,function()use($actor,$id,$msg,$text,$format,$body,$forceRevision){$this->tx->sanctions()->enforce($actor->userId,'conversation');$c=$this->repo->membership($id,$actor->userId);$m=$this->repo->message($id,$msg,(int)$c['joined_sequence']);if($m['author_id']!==$actor->userId)throw new ForumException('Недостаточно прав.',403);foreach($this->repo->participants($id) as $participant){$target=$participant['user_id'];if($target!==$actor->userId&&$this->repo->blocked($actor->userId,$target))throw new ForumException('Редактирование в этой переписке недоступно.',403);}$this->repo->limit($actor->userId,'edit',60,30);$format=$format??$m['body_format'];$text=\CajeerEngine\Forum\Content\ForumContentRenderer::source($text,$format);$this->tx->antiSpam()->publication($actor->userId,'private',$text,$format);$expected=isset($body['expected_revision'])?DraftService::revision($body['expected_revision']):null;$number=$this->tx->revisions()->advance('message',$m,$text,$format,$actor->userId,$expected,DraftService::reason($body['edit_reason']??null),$forceRevision||($body['attachment_ids']??[])!==[]);$this->repo->edit($msg,$text,$format);$this->tx->attachments()->attach($actor->userId,'message',$msg,$body['attachment_ids']??[]);return ['id'=>$msg,'revision_number'=>$number];});
    }
    public function invite(ForumAccessPolicy $actor,string $id,array $body): array
    {
        $ids=$this->recipients($body['participant_ids']??null,$actor->userId);
        return $this->write($actor,function()use($actor,$id,$ids){$user=$actor->userId;$this->tx->sanctions()->enforce($user,'conversation');$c=$this->repo->membership($id,$user);$members=array_column($this->repo->participants($id),'user_id');
            if($c['kind']!=='group'||$this->owner($c,$members)!==$user)throw new ForumException('Недостаточно прав.',403);
            foreach($members as $member)if($member!==$user&&$this->repo->blocked($user,$member))throw new ForumException('Приглашения в эту переписку недоступны.',403);
            $trust=$this->tx->trust()->status($user);TrustService::assertGroup($trust,count($members)+count($ids));
            foreach($ids as $target){if(in_array($target,$members,true))throw new ForumException('Участник уже в переписке.',409);$this->repo->limit($user,'invite',3600,$trust['invitations_hour']);$this->checkInvite($user,$target,$members,$trust);$members[]=$target;$this->repo->join($id,$target,(int)$c['last_sequence']+1);}
            return ['participants'=>$this->repo->participants($id)];
        });
    }
    public function removeParticipant(ForumAccessPolicy $actor,string $id,string $target): array
    {
        return $this->write($actor,function()use($actor,$id,$target){$user=$actor->userId;$c=$this->repo->membership($id,$user);$members=array_column($this->repo->participants($id),'user_id');
            if($c['kind']!=='group'||$this->owner($c,$members)!==$user)throw new ForumException('Недостаточно прав.',403);
            if($target===$user)throw new ForumException('Для выхода используйте покинуть переписку.');
            if(!in_array($target,$members,true))throw new ForumException('Участник не найден.',404);
            $this->repo->leave($id,$target);return ['participants'=>$this->repo->participants($id)];
        });
    }
    public function mark(ForumAccessPolicy $actor,string $id,array $body): array
    {
        $msg=V::id($body['message_id']??null);return $this->write($actor,function()use($actor,$id,$msg){$user=$actor->userId;$c=$this->repo->membership($id,$user);$m=$this->repo->message($id,$msg,(int)$c['joined_sequence']);$this->repo->mark($id,$user,(int)$m['message_sequence']);return ['unread'=>$this->repo->unread($user),'notification_unread'=>$this->repo->unread($user,true)];});
    }
    public function flags(ForumAccessPolicy $actor,string $id,array $body): array
    {
        if($body===[]||array_diff(array_keys($body),['archived','muted'])!==[])throw new ForumException('Некорректные настройки переписки.');foreach($body as $v)V::enabled($v);
        return $this->write($actor,function()use($actor,$id,$body){$this->repo->membership($id,$actor->userId);$this->repo->flags($id,$actor->userId,$body);return ['saved'=>true];});
    }
    public function leave(ForumAccessPolicy $actor,string $id): array
    { return $this->write($actor,function()use($actor,$id){$this->repo->membership($id,$actor->userId);$this->repo->leave($id,$actor->userId);return ['left'=>true];}); }
    private function personalSettings(string $user): array { return $this->repo->settings($user)+['trust'=>$this->tx->trust()->status($user)]; }
    public function settings(ForumAccessPolicy $actor): array { return $this->read($actor,fn()=>$this->personalSettings($actor->userId)); }
    public function setSettings(ForumAccessPolicy $actor,array $body): array
    { $mode=$body['incoming']??null;if(!in_array($mode,['members','trusted','following','none'],true))throw new ForumException('Некорректная настройка.');return $this->write($actor,function()use($actor,$mode){$this->repo->setIncoming($actor->userId,$mode);return $this->personalSettings($actor->userId);}); }
    public function block(ForumAccessPolicy $actor,string $target,array $body): array
    {
        $enabled=V::enabled($body['enabled']??null);return $this->write($actor,function()use($actor,$target,$enabled){if($target===$actor->userId||($enabled&&!$this->repo->activeUser($target)))throw new ForumException('Участник недоступен.',404);$this->repo->block($actor->userId,$target,$enabled);if($enabled)$this->repo->removeFollows($actor->userId,$target);return $this->personalSettings($actor->userId);});
    }
    public function report(ForumAccessPolicy $actor,string $id,string $msg,array $body): array
    {
        $reason=V::text($body['reason']??null,'reason',1000);
        $block=V::enabled($body['block_author']??false);
        return $this->write($actor,function()use($actor,$id,$msg,$reason,$block){
            $c=$this->repo->membership($id,$actor->userId);$m=$this->repo->message($id,$msg,(int)$c['joined_sequence']);
            if($m['author_id']===$actor->userId)throw new ForumException('Нельзя пожаловаться на своё сообщение.');
            $report=$this->repo->existingReport($actor->userId,$msg);
            if($report===null){$this->tx->antiSpam()->action($actor->userId,'report');$this->repo->limit($actor->userId,'report',3600,10);$report=$this->repo->report($actor->userId,$m,$reason);$this->tx->audit('forum.conversation.report.create',$actor->userId,['report_id'=>$report,'message_id'=>$msg]);}
            if($block&&$m['author_id']!==null&&$this->repo->activeUser($m['author_id'])){$this->repo->block($actor->userId,$m['author_id'],true);$this->repo->removeFollows($actor->userId,$m['author_id']);}
            return ['id'=>$report];
        });
    }
    public function reports(ForumAccessPolicy $actor,int $offset,string $status='all'): array
    { if(!in_array($status,['open','resolved','dismissed','all'],true))throw new ForumException('Некорректный статус.');return $this->tx->read(function()use($actor,$offset,$status){$this->tx->permissions()->requireAdmin($actor);return $this->repo->reports($offset,$status);}); }
    public function reportDetail(ForumAccessPolicy $actor,string $id): array
    { return $this->write($actor,function()use($actor,$id){$r=$this->repo->reportDetail($id);$this->tx->audit('forum.conversation.report.view',$actor->userId,['report_id'=>$id]);return $r;},true); }
    public function resolve(ForumAccessPolicy $actor,string $id,array $body): array
    { $status=$body['status']??null;if(!in_array($status,['resolved','dismissed'],true))throw new ForumException('Некорректный статус.');
        $reason=V::text($body['reason']??'','reason',500,0);
        $sanction=$body['sanction']??null;
        if($sanction!==null&&(!is_array($sanction)||$status!=='resolved'||$reason===''))throw new ForumException('Для санкции требуется решение и причина.');
        return $this->write($actor,function()use($actor,$id,$status,$reason,$sanction){
            $r=$this->repo->reportDetail($id);
            if($r['status']!=='open')throw new ForumException('Жалоба уже обработана.',409);
            $issued=null;
            if($sanction!==null){
                if($r['author_id']===null)throw new ForumException('Автор недоступен.',409);
                $issued=$this->tx->sanctions()->issue($actor,$r['author_id'],['reason'=>$reason,'board_id'=>null]+$sanction);
            }
            $this->repo->resolve($id,$actor->userId,$status,$reason,$issued['id']??null);
            $this->tx->audit('forum.conversation.report.resolve',$actor->userId,['report_id'=>$id,'status'=>$status,'sanction_id'=>$issued['id']??null]);
            return ['status'=>$status,'sanction_id'=>$issued['id']??null];
        },true); }
}
