<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Content\ForumContentRenderer;
use CajeerEngine\Forum\Repository\AntiSpamRepository;
use CajeerEngine\Forum\Security\ForumAccessPolicy;

/** All account counters run under the same user lock as the protected mutation. */
final readonly class AntiSpamService
{
    public function __construct(private ForumTransaction $tx,private AntiSpamRepository $repo) {}
    public static function setting(string $name,int $default,int $max=10000): int
    {
        $value=getenv('FORUM_SPAM_'.$name);
        return $value!==false&&ctype_digit($value)?max(1,min($max,(int)$value)):$default;
    }
    public static function limits(bool $new): array
    {
        return [
            'thread'=>[3600,self::setting($new?'NEW_THREADS_HOUR':'THREADS_HOUR',$new?6:30)],
            'reply'=>[600,self::setting($new?'NEW_REPLIES_10MIN':'REPLIES_10MIN',$new?20:60)],
            'edit'=>[600,self::setting('EDITS_10MIN',30)],
            'private'=>[600,self::setting($new?'NEW_PRIVATE_10MIN':'PRIVATE_10MIN',$new?20:60)],
            'report'=>[3600,self::setting('REPORTS_HOUR',10)],
            'upload'=>[3600,self::setting('UPLOADS_HOUR',30)],
            'upload_bytes'=>[3600,self::setting('UPLOAD_MB_HOUR',100,2048)*1048576],
            'reaction'=>[600,self::setting('REACTIONS_10MIN',60)],
            'vote'=>[600,self::setting('VOTES_10MIN',60)],
        ];
    }
    public static function isNew(array $user,int $now): bool
    {
        $raw=(string)($user['created_at']??'');$created=$raw===''?false:strtotime($raw.(preg_match('/(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?)$/',$raw)?'':' UTC'));
        return empty($user['email_verified_at'])||$created===false||$created>$now-86400*7;
    }
     /** Ignore code for link checks and quoted examples for fingerprints. Inspect rendered links and bare URLs. */
    public static function analyze(string $body,string $format): array
    {
        $render=ForumContentRenderer::render($body,$format);
        $html=preg_replace('~<(pre|code)(?:\s[^>]*)?>.*?</\1>~is',' ',$render['html'])??'';
        $text=html_entity_decode(strip_tags(str_replace(['<br>','</p>','</li>','</tr>','</h1>','</h2>','</h3>'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        preg_match_all('~href="(https?://[^"\s]+)"~i',$html,$links);
        preg_match_all('~https?://[^\s<>"\x00-\x1f]+~iu',$text,$bare);
        $urls=array_unique(array_merge(array_map(fn($v)=>html_entity_decode($v,ENT_QUOTES|ENT_HTML5,'UTF-8'),$links[1]),array_map(fn($v)=>rtrim($v,'.,;!?)]}'),$bare[0])));
        $hosts=[];foreach($urls as $url){$host=parse_url($url,PHP_URL_HOST);if(is_string($host)&&$host!=='')$hosts[]=self::host($host);}
        $prose=preg_replace('~<blockquote(?:\s[^>]*)?>.*?</blockquote>~is',' ',$html)??'';
        $prose=html_entity_decode(strip_tags(str_replace(['<br>','</p>','</li>','</tr>','</h1>','</h2>','</h3>'],"\n",$prose)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $normalized=preg_replace('/[\p{Z}\s\p{Cf}]+/u',' ',trim($prose))??'';
        $normalized=function_exists('mb_strtolower')?mb_strtolower($normalized,'UTF-8'):strtolower($normalized);
        return ['hosts'=>$hosts,'mentions'=>count($render['mentions']),'normalized'=>$normalized];
    }
    private static function host(string $host): string
    {
        $host=strtolower(rtrim(rawurldecode($host),'.'));
        if(function_exists('idn_to_ascii')&&preg_match('//u',$host)===1&&$host!=='')$host=idn_to_ascii($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46)?:$host;
        return $host;
    }
    public static function blocked(array $hosts,string $rules): bool
    {
        foreach(explode(',',$rules) as $rule){$rule=self::host(trim($rule," \t\r\n."));if($rule==='')continue;foreach($hosts as $host)if($host===$rule||str_ends_with($host,'.'.$rule))return true;}return false;
    }
    private function newUser(string $user,int $now): bool
    {return self::isNew($this->repo->user($user)??throw new ForumException('Аккаунт недоступен.',403),$now);}
    private function consume(string $user,string $action,int $now,int $amount=1,?string $hash=null,?string $reason=null): void
    {
        [$window,$limit]=self::limits($this->newUser($user,$now))[$action]??throw new \LogicException('Unknown abuse action');
        if($amount>$limit||$this->repo->usage($user,$action,$now-$window)>$limit-$amount)throw new ForumException('Слишком много действий. Повторите позже.',429);
        $this->repo->record($user,$user,$action,$now,$amount,$hash,$reason);
    }
    public function action(string $user,string $action,int $amount=1): void
    { $this->consume($user,$action,time(),$amount); }
    public function publication(string $user,string $action,string $body,string $format,bool $staff=false,string $extra=''): ?string
    {
        $now=time();$a=self::analyze($body,$format);if($extra!==''){$b=self::analyze($extra,'plain');$a['hosts']=array_merge($a['hosts'],$b['hosts']);$a['mentions']+=$b['mentions'];}$new=$this->newUser($user,$now);
        if(self::blocked($a['hosts'],getenv('FORUM_SPAM_BLOCKED_DOMAINS')?:''))throw new ForumException('Ссылка на запрещённый домен.',422);
        // Do not retain raw content or fingerprints of private messages. HMAC also separates accounts.
        $hash=null;$key=getenv('APP_KEY');if($key&&strlen($a['normalized'])>=80&&in_array($action,['thread','reply'],true))$hash=hash_hmac('sha256',$user."\0".$a['normalized'],$key);
        $reason=null;
        if(!$staff){
            if($hash!==null&&$this->repo->duplicate($user,$hash,$now-900))$reason='duplicate';
            if($new&&count(array_filter($a['hosts'],fn($host)=>!self::blocked([$host],getenv('FORUM_SPAM_ALLOWED_DOMAINS')?:'')))>self::setting('NEW_LINKS',2,100))$reason='links';
            if($a['mentions']>self::setting($new?'NEW_MENTIONS':'MENTIONS',$new?5:15,20))$reason='mentions';
        }
        if($reason!==null&&!in_array($action,['thread','reply'],true))throw new ForumException('Содержимое превышает антиспам-ограничения: '.$reason.'.',422);
        $this->consume($user,$action,$now,1,$hash,$reason);
        return $reason;
    }
    /** Count attempts independently of registration success, even for invalid input. */
    public function registration(string $ip): void
    {
        $packed=@inet_pton($ip);if(is_string($packed)&&strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")$packed=substr($packed,12);$identity=$packed===false?'unknown':(strlen($packed)===16?substr($packed,0,8):$packed);
        $key=getenv('APP_KEY');if(!$key)throw new ForumException('Защита регистрации не настроена.',503);$subject=hash_hmac('sha256','register:'.$identity,$key);
        $this->tx->write(function()use($subject){$this->repo->registrationLock();$now=time();if($this->repo->usage($subject,'register',$now-3600)>=self::setting('REGISTRATIONS_HOUR',5)||$this->repo->registrationUsage($now-3600)>=self::setting('REGISTRATIONS_GLOBAL_HOUR',100))throw new ForumException('Слишком много попыток регистрации. Повторите позже.',429);$this->repo->record(null,$subject,'register',$now);});
    }
    public function stats(ForumAccessPolicy $actor): array
    {return $this->tx->read(function()use($actor){$this->tx->permissions()->requireAdmin($actor);$now=time();return ['actions'=>$this->repo->stats($now-86400),'review_reasons'=>$this->repo->reasons($now-86400),'new_limits'=>self::limits(true),'regular_limits'=>self::limits(false)];});}
    public function cleanup(): int {return $this->tx->write(fn()=>$this->repo->cleanup(time()-86400));}
}
