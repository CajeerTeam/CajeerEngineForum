<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{WatchRepository,ThreadRepository};
use CajeerEngine\Forum\Security\{ForumAccessPolicy,IdentityMail};
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class WatchService
{
    public function __construct(private ForumTransaction $tx,private WatchRepository $repo,private ThreadRepository $threads) {}
    public function mode(string $kind,string $id,string $user): ?string {return $user===''?null:$this->repo->mode($kind,$id,$user);}
    public function set(ForumAccessPolicy $actor,string $kind,string $id,bool $enabled,mixed $mode=null): array
    {
        $id=V::id($id);$actor->require('forum:write');
        if($mode!==null && !in_array($mode,WatchRepository::MODES,true))throw new ForumException('Некорректный режим подписки.',422);
        return $this->tx->write(function()use($actor,$kind,$id,$enabled,$mode){
            $board=$kind==='thread'?($this->threads->lockThread($id)['board_id']??throw new ForumException('Тема не найдена.',404)):$id;
            $this->tx->permissions()->assertWrite($actor,$board,'watch:use');
            $old=$this->repo->mode($kind,$id,$actor->userId);$next=$enabled?($mode??$old??'internal'):null;
            if(in_array($next,WatchRepository::EMAIL_MODES,true))$this->assertEmail($actor->userId);
            if($old!==$next){$this->repo->set($kind,$id,$actor->userId,$next);$this->repo->cancel($kind,$id,$actor->userId);$this->tx->audit('forum.watch.set',$actor->userId,['kind'=>$kind,'id'=>$id,'mode'=>$next]);}
            return ['enabled'=>$enabled,'mode'=>$next];
        });
    }
    private function assertEmail(string $user): void
    {
        $row=$this->repo->user($user);
        if(empty($row['email_verified_at']))throw new ForumException('Для email-подписок подтвердите адрес электронной почты.',422);
    }
    public function settings(ForumAccessPolicy $actor,int $offset): array
    {
        return $this->tx->read(function()use($actor,$offset){$this->tx->permissions()->requireCurrent($actor,'forum:write');$allowed=NodePermissionService::allowed($this->tx->permissions()->access($actor));return ['boards'=>$this->repo->boards($actor->userId,$allowed,$offset),'auto_mode'=>$this->repo->autoMode($actor->userId),'email_verified'=>!empty($this->repo->user($actor->userId)['email_verified_at']),'delivery'=> $this->repo->stats($actor->userId,$allowed)];});
    }
    public function preferences(ForumAccessPolicy $actor,mixed $mode): array
    {
        if(!in_array($mode,['off',...WatchRepository::MODES],true))throw new ForumException('Некорректный режим автоподписки.',422);
        return $this->tx->write(function()use($actor,$mode){$this->tx->permissions()->requireCurrent($actor,'forum:write',true);if(in_array($mode,WatchRepository::EMAIL_MODES,true))$this->assertEmail($actor->userId);$this->repo->setAutoMode($actor->userId,$mode);return ['auto_mode'=>$mode];});
    }
    public function published(string $actor,string $thread,string $post,bool $reply): void
    {
        $board=$this->repo->threadBoard($thread)??throw new \LogicException('Missing publication board');
        $mode=$this->repo->autoMode($actor);
        if($mode!=='off'){
            $access=$this->tx->permissions()->access(new ForumAccessPolicy($actor,['*']));
            if(($access['boards'][$board]['watch:use']??false) && (!in_array($mode,WatchRepository::EMAIL_MODES,true)||!empty($this->repo->user($actor)['email_verified_at'])))$this->repo->set('thread',$thread,$actor,$mode,true);
        }
        $internal=$this->repo->published($actor,$thread,$post,$board,$reply);
        if($internal!==[])$this->tx->enqueueWatchNotification($actor,$post,$internal);
    }
    private static function key(): string
    { $key=getenv('APP_KEY');if(!is_string($key)||strlen($key)<32)throw new \RuntimeException('APP_KEY required for unsubscribe');return hash_hmac('sha256','forum-unsubscribe-v1',$key,true); }
    public static function token(string $user,string $email,int $expires): string
    { $payload=$user.'.'.$expires;return $payload.'.'.hash_hmac('sha256',$payload.'.'.strtolower($email),self::key()); }
    public function unsubscribe(mixed $token): array
    {
        if(!is_string($token)||!preg_match('/^([a-f0-9-]{36})\.([0-9]{1,12})\.([a-f0-9]{64})$/D',$token,$m)||(int)$m[2]<time())throw new ForumException('Недействительная ссылка отписки.',422);
        $id=V::id($m[1]);
        return $this->tx->write(function()use($id,$token,$m){$user=$this->repo->lockUser($id);if($user===null||!hash_equals(self::token($id,$user['email'],(int)$m[2]),$token))throw new ForumException('Недействительная ссылка отписки.',422);$this->repo->unsubscribe($id);return ['unsubscribed'=>true];});
    }
    /** Re-resolve current recipient and publication ACLs, not stale queued metadata. */
    private function deliveryPayload(string $token,string $recipient): array
    {
        return $this->tx->read(function()use($token,$recipient){
            $user=$this->repo->user($recipient);$rows=$this->repo->leased($token);$valid=[];$allowed=[];
            if($user!==null&&$user['status']==='active'&&!empty($user['email_verified_at'])){
                try{$allowed=NodePermissionService::allowed($this->tx->permissions()->access(new ForumAccessPolicy($user['id'],['*'])),'watch:use');}catch(ForumException $e){if($e->status!==401)throw $e;}
            }
            foreach($rows as $r){
                if(!in_array($r['current_board'],$allowed,true)||!in_array($r['board_id'],$allowed,true))continue;
                $kind=$r['source_kind'];$id=$kind==='thread'?$r['thread_id']:$r['board_id'];
                if($kind==='board'&&$r['current_board']!==$r['board_id'])continue;
                if($this->repo->mode($kind,$id,$r['user_id'])!==$r['mode'])continue;
                $valid[]=$r;
            }
            return ['user'=>$user,'rows'=>$valid,'all'=>array_column($rows,'id')];
        });
    }
    /** Drain at most a bounded number of SMTP messages; lease survives process termination. */
    public function dispatch(int $limit=5): array
    {
        $stats=['sent'=>0,'failed'=>0,'cancelled'=>0];
        for($i=0;$i<max(1,min(10,$limit));$i++){
            $now=time();$candidate=$this->repo->candidate($now);if($candidate===null)break;
            $token=Uuid::v4();$ids=$this->tx->write(fn()=> $this->repo->claim($candidate,$now,$token));if(!$ids)continue;
            try{
                $payload=$this->deliveryPayload($token,$candidate['user_id']);
                $valid=array_column($payload['rows'],'id');$invalid=array_diff($payload['all'],$valid);
                $stats['cancelled']+=$this->tx->write(fn()=> $this->repo->complete($token,$invalid,'cancelled',time()));
                if(!$valid)continue;
                $user=$payload['user'];$url=IdentityMail::baseUrl();$unsubscribe=self::token($user['id'],$user['email'],time()+90*86400);
                // Never copy private titles or bodies into email. The authenticated forum resolves all links.
                $links=[];foreach($payload['rows'] as $r)$links[$r['thread_id']]=$url.'/forum/threads/'.$r['thread_id'].'?unread=1';
                $body="You have ".count($valid)." new subscription updates. Sign in to view them:\r\n".implode("\r\n",$links)."\r\n\r\nManage subscriptions: $url/forum/watches\r\nStop subscription emails: $url/forum/unsubscribe#token=$unsubscribe\r\n";
                // A concurrent ACL change, unsubscribe, deletion or expired lease can invalidate
                // the prepared batch. Recheck the current principal and the exact lease at the
                // transport boundary. No network I/O is performed inside a database transaction.
                $fresh=$this->deliveryPayload($token,$candidate['user_id']);
                $freshIds=array_column($fresh['rows'],'id');
                if($freshIds!==$valid || array_column($fresh['rows'],'thread_id')!==array_column($payload['rows'],'thread_id') || ($fresh['user']['email']??null)!==$user['email'] || !$this->repo->ownsBatch($token,$valid,time())) {
                    $stats['cancelled']+=$this->tx->write(function()use($token,$fresh,$freshIds){
                        $n=$this->repo->complete($token,array_diff($fresh['all'],$freshIds),'cancelled',time());
                        $this->repo->releaseUnsent($token);
                        return $n;
                    });
                    continue;
                }
                (new IdentityMail())->deliver($user['email'],$candidate['mode']==='email'?'New forum subscription update':'Forum subscription digest',$body,'forum-'.hash('sha256',implode(',',$valid)));
                $stats['sent']+=$this->tx->write(fn()=> $this->repo->complete($token,$valid,'sent',time()));
            }catch(\Throwable $e){$this->tx->write(fn()=> $this->repo->retry($token,time()));$stats['failed']++;}
        }
        return $stats;
    }
}
