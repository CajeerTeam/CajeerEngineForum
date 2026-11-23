<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

/** Database-owned community policy. Infrastructure secrets and operator ceilings are not editable. */
final readonly class ForumCommunitySettings
{
    public function __construct(private DatabaseManager $db, private ?ForumTransaction $tx=null) {}

    public static function defaults(): array
    {
        return ['name'=>getenv('APP_NAME')?:'CajeerEngine Forum Edition',
            'description'=>'Обсуждайте идеи, делитесь опытом и находите ответы вместе.',
            'registration_enabled'=>self::operatorRegistration(), 'analytics_enabled'=>false, 'metrika_goals'=>self::metrikaGoals(), 'ga4_events'=>self::ga4Events(), 'matomo_events'=>self::alternativeEvents(), 'plausible_events'=>self::alternativeEvents(), 'gtm_events'=>self::alternativeEvents(), 'trust_days'=>7,'trust_posts'=>3,
            'threads_hour'=>AntiSpamService::setting('THREADS_HOUR',30),
            'new_threads_hour'=>AntiSpamService::setting('NEW_THREADS_HOUR',6),
            'replies_10min'=>AntiSpamService::setting('REPLIES_10MIN',60),
            'new_replies_10min'=>AntiSpamService::setting('NEW_REPLIES_10MIN',20),
            'attachment_mb'=>intdiv(AttachmentService::limits()['file_bytes'],1048576),
            'attachment_quota_mb'=>intdiv(AttachmentService::limits()['quota_bytes'],1048576),
            'pause_from'=>0,'pause_until'=>0];
    }
    /** Identifiers must match explicitly configured JavaScript-event goals in Yandex Metrica. */
    public static function metrikaGoals(): array
    {
        return ['forum_index_view'=>'','forum_board_view'=>'','forum_thread_view'=>'',
            'forum_paginated_view'=>'','forum_attachment_click'=>'','forum_outbound_click'=>''];
    }
    public static function validateMetrikaGoals(mixed $goals): array
    {
        $defaults=self::metrikaGoals();
        if(!is_array($goals)||array_diff_key($goals,$defaults)||array_diff_key($defaults,$goals))
            throw new ForumException('Некорректный набор целей Яндекс Метрики.',422);
        foreach($goals as $value) {
            if(!is_string($value)||($value!==''&&!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D',$value)))
                throw new ForumException('Некорректный идентификатор цели Яндекс Метрики.',422);
        }
        // One goal may not mean several different actions within the same counter.
        $configured=array_filter(array_values($goals),static fn(string $v):bool=>$v!=='');
        if(count($configured)!==count(array_unique($configured)))
            throw new ForumException('Идентификаторы целей Яндекс Метрики должны быть уникальны.',422);
        return $goals;
    }
    /** GA4 custom events are available without a separate GA4 event-registration step. */
    public static function ga4Events(): array
    {
        return array_combine(array_keys(self::metrikaGoals()),array_keys(self::metrikaGoals()));
    }
    public static function validateGa4Events(mixed $events): array
    {
        $defaults=self::ga4Events();
        if(!is_array($events)||array_diff_key($events,$defaults)||array_diff_key($defaults,$events))
            throw new ForumException('Некорректный набор событий GA4.',422);
        foreach($events as $value) {
            if(!is_string($value))throw new ForumException('Некорректное имя события GA4.',422);
            if($value!=='' && (!\CajeerEngine\Analytics\EventConfig::name($value)
                || in_array($value,['page_view','click','scroll','search','file_download','outbound_click','login','sign_up','form_submit','generate_lead','first_visit','user_engagement','screen_view'],true)))
                throw new ForumException('Недопустимое или зарезервированное имя события GA4.',422);
        }
        $configured=array_filter(array_values($events),static fn(string $v):bool=>$v!=='');
        if(count($configured)!==count(array_unique($configured)))
            throw new ForumException('События GA4 должны иметь уникальные имена.',422);
        return $events;
    }
    /** Provider destinations are public event names, never user-controlled visitor fields. */
    public static function alternativeEvents(): array
    {
        return array_combine(array_keys(self::metrikaGoals()),array_keys(self::metrikaGoals()));
    }
    public static function validateAlternativeEvents(mixed $events,string $provider): array
    {
        if (!in_array($provider,['matomo','plausible','gtm'],true))throw new \LogicException('Unknown analytics provider');
        $defaults=self::alternativeEvents();
        if (!is_array($events)||array_diff_key($events,$defaults)||array_diff_key($defaults,$events))
            throw new ForumException('Некорректный набор событий '.$provider.'.',422);
        foreach ($events as $value) {
            if (!is_string($value)||($value!==''&&!\CajeerEngine\Analytics\EventConfig::name($value))||in_array($value,['page_view','pageview'],true))
                throw new ForumException('Недопустимое имя события '.$provider.'.',422);
        }
        $configured=array_filter(array_values($events),static fn(string $v):bool=>$v!=='');
        if (count($configured)!==count(array_unique($configured)))
            throw new ForumException('Имена событий '.$provider.' должны быть уникальны.',422);
        return $events;
    }
    public static function operatorRegistration(): bool
    { return filter_var(getenv('FORUM_REGISTRATION')===false?'true':getenv('FORUM_REGISTRATION'),FILTER_VALIDATE_BOOLEAN); }

    public function snapshot(bool $lock=false): array
    {
        $pdo=$this->db->connection();
        // Only used in write transactions; PostgreSQL readers share the policy lock.
        $sql='SELECT revision, payload FROM ce_forum_community_settings WHERE id=1';
        if($lock && $this->db->driver()!=='sqlite')$sql.=$this->db->driver()==='pgsql'?' FOR SHARE':' LOCK IN SHARE MODE';
        $row=$pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);
        if(!$row)throw new \LogicException('Forum community policy missing; run migrations');
        return ['revision'=>(int)$row['revision'],'values'=>self::effective(json_decode((string)$row['payload'],true,512,JSON_THROW_ON_ERROR))];
    }
    public static function effective(array $stored): array
    {
        $v=array_replace(self::defaults(),$stored);
        $v['registration_open']=$v['registration_enabled']&&self::operatorRegistration();
        $v['paused']=$v['pause_from']>0&&$v['pause_from']<=time()&&($v['pause_until']===0||$v['pause_until']>time());
        return $v;
    }
    public static function validate(array $input): array
    {
        // Preserve older API clients while upgrades migrate the policy document in place.
        $input+=['metrika_goals'=>self::metrikaGoals(),'ga4_events'=>self::ga4Events(), 'matomo_events'=>self::alternativeEvents(), 'plausible_events'=>self::alternativeEvents(), 'gtm_events'=>self::alternativeEvents()];
        $keys=array_keys(self::defaults());
        if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input)))throw new ForumException('Передайте все поля политики без неизвестных ключей.',422);
        $out=['name'=>V::text($input['name'],'name',100),'description'=>V::text($input['description'],'description',500,0)];
        if(!is_bool($input['registration_enabled']))throw new ForumException('Некорректный флаг регистрации.');
        $out['registration_enabled']=$input['registration_enabled'];
        if(!is_bool($input['analytics_enabled']))throw new ForumException('Некорректный флаг аналитики форума.',422);
        $out['analytics_enabled']=$input['analytics_enabled'];
        $out['metrika_goals']=self::validateMetrikaGoals($input['metrika_goals']);
        $out['ga4_events']=self::validateGa4Events($input['ga4_events']);
        foreach (['matomo','plausible','gtm'] as $provider) $out[$provider.'_events']=self::validateAlternativeEvents($input[$provider.'_events'],$provider);
        foreach(['trust_days'=>[1,365],'trust_posts'=>[1,1000],'threads_hour'=>[1,10000],'new_threads_hour'=>[1,10000],'replies_10min'=>[1,10000],'new_replies_10min'=>[1,10000],'attachment_mb'=>[1,25],'attachment_quota_mb'=>[1,2048],'pause_from'=>[0,4102444800],'pause_until'=>[0,4102444800]] as $key=>[$min,$max]){
            $n=$input[$key];if(!is_int($n)||$n<$min||$n>$max)throw new ForumException('Некорректное значение: '.$key,422);$out[$key]=$n;
        }
        if($out['new_threads_hour']>$out['threads_hour']||$out['new_replies_10min']>$out['replies_10min']||$out['attachment_mb']>$out['attachment_quota_mb'])throw new ForumException('Несогласованные лимиты.',422);
        if($out['pause_until']!==0&&($out['pause_from']===0||$out['pause_until']<=$out['pause_from']))throw new ForumException('Окончание обслуживания должно быть позже начала.',422);
        return $out;
    }
    public function save(ForumAccessPolicy $actor,array $input): array
    {
        $tx=$this->tx??throw new \LogicException('Missing transaction');
        $revision=$input['revision']??null;$values=$input['values']??null;
        if(!is_int($revision)||$revision<0||!is_array($values))throw new ForumException('Некорректная версия политики.',422);
        return $tx->write(function()use($actor,$revision,$values,$tx){
            $tx->permissions()->requireCurrent($actor,'forum:acp:settings',true);
            $before=$this->snapshot();
            if($before['revision']!==$revision)throw new ForumException('Политика изменена. Обновите страницу.',409);
            $values+=['metrika_goals'=>$before['values']['metrika_goals'],'ga4_events'=>$before['values']['ga4_events'],'matomo_events'=>$before['values']['matomo_events'],'plausible_events'=>$before['values']['plausible_events'],'gtm_events'=>$before['values']['gtm_events']];
            $values=self::validate($values);
            // Maintenance scheduling is a separate permission, even when editing the same document.
            if($values['pause_from']!==$before['values']['pause_from']||$values['pause_until']!==$before['values']['pause_until'])$tx->permissions()->requireCurrent($actor,'forum:acp:maintenance',true);
            $s=$this->db->connection()->prepare('UPDATE ce_forum_community_settings SET revision=revision+1,payload=? WHERE id=1 AND revision=?');
            $s->execute([json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$revision]);
            if($s->rowCount()!==1)throw new ForumException('Политика изменена. Обновите страницу.',409);
            $after=$this->snapshot();$tx->audit('forum.acp.policy.update',$actor->userId,['before'=>$before,'after'=>$after]);return $after;
        });
    }
    public function assertPublishing(): void
    { if($this->snapshot(true)['values']['paused'])throw new ForumException('Публикация временно приостановлена на время обслуживания.',503); }
}
