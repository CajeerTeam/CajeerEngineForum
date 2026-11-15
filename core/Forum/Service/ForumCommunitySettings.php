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
            'registration_enabled'=>self::operatorRegistration(), 'trust_days'=>7,'trust_posts'=>3,
            'threads_hour'=>AntiSpamService::setting('THREADS_HOUR',30),
            'new_threads_hour'=>AntiSpamService::setting('NEW_THREADS_HOUR',6),
            'replies_10min'=>AntiSpamService::setting('REPLIES_10MIN',60),
            'new_replies_10min'=>AntiSpamService::setting('NEW_REPLIES_10MIN',20),
            'attachment_mb'=>intdiv(AttachmentService::limits()['file_bytes'],1048576),
            'attachment_quota_mb'=>intdiv(AttachmentService::limits()['quota_bytes'],1048576),
            'pause_from'=>0,'pause_until'=>0];
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
        $keys=array_keys(self::defaults());
        if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input)))throw new ForumException('Передайте все поля политики без неизвестных ключей.',422);
        $out=['name'=>V::text($input['name'],'name',100),'description'=>V::text($input['description'],'description',500,0)];
        if(!is_bool($input['registration_enabled']))throw new ForumException('Некорректный флаг регистрации.');
        $out['registration_enabled']=$input['registration_enabled'];
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
        $values=self::validate($values);
        return $tx->write(function()use($actor,$revision,$values,$tx){
            $tx->permissions()->requireCurrent($actor,'forum:acp:settings',true);
            $before=$this->snapshot();
            if($before['revision']!==$revision)throw new ForumException('Политика изменена. Обновите страницу.',409);
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
