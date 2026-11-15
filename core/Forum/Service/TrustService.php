<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\ConversationRepository;
use CajeerEngine\Forum\Repository\AntiSpamRepository;

/** Live, non-transferable trust; never grants an ACL, role or sanction bypass. */
final readonly class TrustService
{
    public const DAYS=7;
    public const POSTS=3;
    public function __construct(private AntiSpamRepository $users, private ConversationRepository $contacts, private ?ForumCommunitySettings $settings=null) {}
    public static function assess(array $user,int $posts,int $now,int $requiredDays=self::DAYS,int $requiredPosts=self::POSTS): array
    {
        $verified=!empty($user['email_verified_at']);
        $raw=(string)($user['created_at']??'');
        $created=$raw===''?false:strtotime($raw.(preg_match('/(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?)$/',$raw)?'':' UTC'));
        $days=$created===false?0:max(0,intdiv($now-$created,86400));
        $trusted=$verified&&$days>=$requiredDays&&$posts>=$requiredPosts;
        return ['level'=>$trusted?'established':'new','email_verified'=>$verified,'account_days'=>$days,'published_posts'=>$posts,'required_days'=>$requiredDays,'required_posts'=>$requiredPosts,'new_conversations_hour'=>$trusted?5:3,'group_participants_max'=>$trusted?20:4,'invitations_hour'=>$trusted?20:6];
    }
    public function status(string $user): array
    {
        $row=$this->users->user($user)??throw new ForumException('Аккаунт недоступен.',403);
        $v=$this->settings?->snapshot()['values']??ForumCommunitySettings::defaults();
        return self::assess($row,$this->contacts->publishedCount($user),time(),$v['trust_days'],$v['trust_posts']);
    }
    public static function accepts(string $mode,bool $trusted,bool $followed): bool
    {
        return match($mode){'members'=>true,'trusted'=>$trusted,'following'=>$followed,default=>false};
    }
    public static function assertGroup(array $trust,int $size): void
    {
        if($size>$trust['group_participants_max'])throw new ForumException('Для расширения группы требуется установленный уровень доверия.',403);
    }
}
