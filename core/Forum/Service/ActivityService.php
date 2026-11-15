<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\ActivityRepository;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class ActivityService
{
    public const RULES=['first_post'=>'posts','active_member'=>'posts','topic_author'=>'threads','helpful_member'=>'reactions','veteran'=>'days'];
    public function __construct(private ForumTransaction $tx,private ActivityRepository $repo,private MemberProfileService $profiles){}
    private function publicBoards(): array {return NodePermissionService::allowed($this->tx->permissions()->access(new ForumAccessPolicy('',[])));}
    public function summary(array $member,ForumAccessPolicy $actor): ?array
    {
        if(!$member['activity_visible'])return null;
        $stats=$this->repo->stats($member['id'],$this->publicBoards());
        $joined=(new \DateTimeImmutable((string)$member['created_at'],new \DateTimeZone('UTC')))->getTimestamp();
        $metrics=$stats+['days'=>max(0,intdiv(time()-$joined,86400))];
        $badges=[];
        foreach($this->repo->rules() as $rule){$code=$rule['code'];if((int)$rule['enabled']===1&&isset(self::RULES[$code])&&$metrics[self::RULES[$code]]>=(int)$rule['threshold'])$badges[]=$code;}
        return $stats+['achievements'=>$badges];
    }
    public function feed(ForumAccessPolicy $actor,mixed $scope,int $offset): array
    {
        if(!in_array($scope,['all','mine','following'],true))throw new ForumException('Некорректная лента.',422);
        if($scope!=='all'&&$actor->userId==='')throw new ForumException('Требуется авторизация.',401);
        return $this->tx->read(fn()=> $this->repo->feed($actor->userId,$scope,NodePermissionService::allowed($this->tx->permissions()->access($actor)),$offset));
    }
    public function leaderboard(ForumAccessPolicy $actor,int $offset): array
    {return $this->tx->read(function()use($actor,$offset){$this->tx->permissions()->access($actor);return $this->repo->leaderboard($this->publicBoards(),$offset);});}
    public function relationship(ForumAccessPolicy $actor,string $id): array
    {
        return $this->tx->read(function()use($actor,$id){$this->tx->permissions()->requireCurrent($actor,'forum:write');return ['follow'=>$this->repo->relation($actor->userId,$id,'follow'),'ignore'=>$this->repo->relation($actor->userId,$id,'ignore')];});
    }
    public function relations(ForumAccessPolicy $actor,mixed $kind,int $offset): array
    {
        if(!in_array($kind,['follow','ignore'],true))throw new ForumException('Некорректный список.',422);
        return $this->tx->read(function()use($actor,$kind,$offset){$this->tx->permissions()->requireCurrent($actor,'forum:write');return $this->repo->relations($actor->userId,$kind,$offset);});
    }
    public function setRelation(ForumAccessPolicy $actor,string $id,array $data): array
    {
        $kind=$data['kind']??null;if(!in_array($kind,['follow','ignore'],true)||$id===$actor->userId)throw new ForumException('Некорректное назначение.',422);
        $enabled=V::enabled($data['enabled']??null);
        return $this->tx->write(function()use($actor,$id,$kind,$enabled){
            if($kind==='follow')$this->tx->contacts()->lockWrites();
            $this->tx->permissions()->requireCurrent($actor,'forum:write',true);
            if($kind==='follow'&&$enabled&&$this->tx->contacts()->blocked($actor->userId,$id))throw new ForumException('Взаимодействие недоступно.',403);
            if($enabled)$this->profiles->publicProfile($id,$actor);
            $this->repo->setRelation($actor->userId,$id,$kind,$enabled);
            return ['kind'=>$kind,'enabled'=>$enabled];
        });
    }
    public function rules(ForumAccessPolicy $actor): array {return $this->tx->read(function()use($actor){$this->tx->permissions()->requireAdmin($actor);return $this->repo->rules();});}
    public function saveRule(ForumAccessPolicy $actor,mixed $code,array $data): array
    {
        if(!is_string($code)||!isset(self::RULES[$code]))throw new ForumException('Достижение не найдено.',404);
        $threshold=$data['threshold']??null;if(!is_int($threshold)||$threshold<1||$threshold>1000000)throw new ForumException('Некорректный порог.',422);
        $enabled=V::enabled($data['enabled']??null);
        return $this->tx->write(function()use($actor,$code,$threshold,$enabled){$this->tx->permissions()->requireAdmin($actor,true);$this->repo->saveRule($code,$threshold,$enabled);$this->tx->audit('forum.achievement.rule',$actor->userId,['code'=>$code,'threshold'=>$threshold,'enabled'=>$enabled]);return $this->repo->rules();});
    }
}
