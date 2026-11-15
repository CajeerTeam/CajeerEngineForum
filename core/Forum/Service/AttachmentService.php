<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{AttachmentRepository,ThreadRepository,ConversationRepository,BoardRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

final readonly class AttachmentService
{
    public function __construct(private ForumTransaction $tx,private AttachmentRepository $repo,private ThreadRepository $threads,private ConversationRepository $conversations,private BoardRepository $boards) {}
    public static function limits(): array
    {
        $mb=1048576;
        return ['file_bytes'=>max(1,min(25,(int)(getenv('FORUM_ATTACHMENT_MAX_MB')?:5)))*$mb,'quota_bytes'=>max(1,min(2048,(int)(getenv('FORUM_ATTACHMENT_QUOTA_MB')?:100)))*$mb,'message_bytes'=>50*$mb,'message_files'=>10,'quota_files'=>1000,'pending_seconds'=>86400,'pending_files'=>100];
    }
    private function limitsFor(string $user): array
    {
        $limits=self::limits();$configured=getenv('FORUM_ATTACHMENT_GROUP_LIMITS');
        if($configured===false||$configured==='')return $limits;
        $map=json_decode($configured,true);$group=$this->repo->primaryGroup($user);
        if(!is_array($map)||$group===null||!is_array($map[$group]??null))return $limits;
        $rule=$map[$group];
        foreach(['file_mb'=>['file_bytes',25],'quota_mb'=>['quota_bytes',2048]] as $key=>[$target,$max])if(is_int($rule[$key]??null)&&$rule[$key]>=1&&$rule[$key]<=$max)$limits[$target]=$rule[$key]*1048576;
        return $limits;
    }
    /** Strict allowlist; never render user uploads inline. No archive extraction. */
    public static function validate(string $filename,string $data,int $maxBytes=26214400): array
    {
        $size=strlen($data);if($size<1||$size>$maxBytes)throw new ForumException('Недопустимый размер файла.',413);
        if(strlen($filename)>180||!preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} ._()\-]*\z/uD',$filename)||str_contains($filename,'..'))throw new ForumException('Недопустимое имя файла.',422);
        $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        foreach(explode('.',strtolower($filename)) as $part)if(in_array($part,['php','phtml','phar','exe','dll','sh','js','html','htm','svg','bat','cmd','ps1','com','cgi','asp','aspx','jsp','scr'],true))throw new ForumException('Запрещённое расширение.',415);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $allowed=['txt'=>['text/plain'],'csv'=>['text/plain','text/csv'],'pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'webp'=>['image/webp'],'zip'=>['application/zip']];
        if(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true))throw new ForumException('Тип файла не поддерживается.',415);
        if(in_array($ext,['png','jpg','jpeg','webp'],true)){$image=@getimagesizefromstring($data);if(!$image||$image['mime']!==$mime||$image[0]<1||$image[1]<1||$image[0]*$image[1]>40000000)throw new ForumException('Некорректное изображение.',422);}
        if($ext==='pdf'&&!str_starts_with($data,'%PDF-'))throw new ForumException('Некорректный PDF.',422);
        return ['filename'=>$filename,'mime'=>$mime,'size_bytes'=>$size,'sha256'=>hash('sha256',$data)];
    }
    private static function publicMeta(array $a): array
    {return ['id'=>$a['id'],'filename'=>$a['filename'],'mime'=>$a['mime'],'size_bytes'=>(int)$a['size_bytes'],'sha256'=>$a['sha256'],'download_url'=>'/api/v1/forum/attachments/'.$a['id'].'/download'];}
    public function upload(ForumAccessPolicy $actor,string $filename,string $data): array
    {
        $meta=self::validate($filename,$data);return $this->tx->write(function()use($actor,$meta,$data){
            $this->tx->permissions()->requireCurrent($actor,'forum:write',true);
            $this->tx->sanctions()->enforce($actor->userId,'upload');
            $limits=$this->limitsFor($actor->userId);$usage=$this->repo->usage($actor->userId);
            if($meta['size_bytes']>$limits['file_bytes'])throw new ForumException('Превышен лимит размера файла.',413);
            if($this->repo->pendingCount($actor->userId)>=$limits['pending_files'])throw new ForumException('Слишком много временных файлов.',409);
            if((int)$usage['files']>=$limits['quota_files']||(int)$usage['bytes']+$meta['size_bytes']>$limits['quota_bytes'])throw new ForumException('Квота вложений исчерпана.',409);
            $this->tx->antiSpam()->action($actor->userId,'upload');$this->tx->antiSpam()->action($actor->userId,'upload_bytes',$meta['size_bytes']);
            $now=time();$a=$meta+['id'=>Uuid::v4(),'owner_id'=>$actor->userId,'created_at'=>$now,'expires_at'=>$now+$limits['pending_seconds']];
            $this->repo->insert($a,$data);$this->tx->media()->enqueue($a);$this->tx->audit('forum.attachment.upload',$actor->userId,['attachment_id'=>$a['id'],'size_bytes'=>$a['size_bytes']]);return $this->tx->media()->decorate([self::publicMeta($a)])[0];
        });
    }
    public function pending(ForumAccessPolicy $actor): array
    {return $this->tx->read(function()use($actor){$this->tx->permissions()->requireCurrent($actor,'forum:write');return ['items'=>$this->tx->media()->decorate(array_map(self::publicMeta(...),$this->repo->pending($actor->userId,time()))),'usage'=>array_map('intval',$this->repo->usage($actor->userId))+['variant_bytes'=>$this->tx->media()->usage($actor->userId)],'limits'=>$this->limitsFor($actor->userId)+['variant_quota_bytes'=>ForumMediaService::variantQuota()]];});}
    /** Called inside publication/edit transaction, after current principal and target are locked. */
    public function attach(string $user,string $kind,string $target,mixed $ids): void
    {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>10)throw new ForumException('Недопустимый список вложений.',422);
        if(!$ids)return;$this->tx->sanctions()->enforce($user,'upload');$ids=array_map(V::id(...),$ids);if(count(array_unique($ids))!==count($ids))throw new ForumException('Повторяющиеся вложения.',422);sort($ids);
        $existing=$this->repo->attached($kind,[$target]);$bytes=array_sum(array_column($existing,'size_bytes'));$now=time();$new=[];
        foreach($ids as $id){$a=$this->repo->lockItem($id);if(!$a||$a['owner_id']!==$user||$a['post_id']!==null||$a['message_id']!==null||(int)$a['expires_at']<=$now||$this->repo->held($id))throw new ForumException('Вложение недоступно.',404);$bytes+=(int)$a['size_bytes'];$new[]=$id;}
        if(count($existing)+count($new)>10||$bytes>self::limits()['message_bytes'])throw new ForumException('Превышен лимит вложений сообщения.',422);
        foreach($new as $id)if(!$this->repo->bind($kind,$target,$id,$user,$now))throw new ForumException('Вложение изменилось. Повторите запрос.',409);
        $this->tx->audit('forum.attachment.bind',$user,['kind'=>$kind,'target_id'=>$target,'attachment_ids'=>$new]);
    }
    public function decorate(string $kind,array $messages): array
    {
        $by=[];$key=$kind==='post'?'post_id':'message_id';$files=$this->repo->attached($kind,array_column($messages,'id'));$meta=$this->tx->media()->decorate(array_map(self::publicMeta(...),$files));foreach($files as $i=>$a)$by[$a[$key]][]=$meta[$i];
        foreach($messages as &$m)$m['attachments']=$by[$m['id']]??[];unset($m);return $messages;
    }
    private function authorize(ForumAccessPolicy $actor,array $a): void
    {
        if($a['post_id']!==null){$p=$this->repo->post($a['post_id'])??throw new ForumException('Вложение не найдено.',404);NodePermissionService::assert($this->tx->permissions()->access($actor),$p['board_id'],'thread:read');return;}
        if($a['message_id']!==null){$this->tx->permissions()->requireCurrent($actor,'forum:write');$m=$this->repo->message($a['message_id'])??throw new ForumException('Вложение не найдено.',404);$c=$this->conversations->membership($m['conversation_id'],$actor->userId);$this->conversations->message($m['conversation_id'],$m['id'],(int)$c['joined_sequence']);return;}
        $this->tx->permissions()->requireCurrent($actor,'forum:write');if($a['owner_id']!==$actor->userId||(int)$a['expires_at']<=time())throw new ForumException('Вложение не найдено.',404);
    }
    public function get(ForumAccessPolicy $actor,string $id,bool $download=false): array
    {
        return $this->tx->read(function()use($actor,$id,$download){$a=$this->repo->find($id)??throw new ForumException('Вложение не найдено.',404);try{$this->authorize($actor,$a);}catch(ForumException $e){if(in_array($e->status,[401,403],true))throw new ForumException('Вложение не найдено.',404);throw $e;}$meta=$this->tx->media()->decorate([self::publicMeta($a)])[0];if(!$download)return $meta;
            $stored=$this->repo->bytes($id);$data=base64_decode($stored['file_data']??'',true);if($data===false||strlen($data)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash('sha256',$data)))throw new ForumException('Файл повреждён.',503);return $meta+['data'=>$data];});
    }
    public function image(ForumAccessPolicy $actor,string $id,string $variant): array
    {return $this->tx->read(function()use($actor,$id,$variant){$a=$this->repo->find($id)??throw new ForumException('Вложение не найдено.',404);try{$this->authorize($actor,$a);}catch(ForumException $e){if(in_array($e->status,[401,403],true))throw new ForumException('Вложение не найдено.',404);throw $e;}return $this->tx->media()->image($id,$variant);});}
    public function mediaStats(ForumAccessPolicy $actor): array {return $this->tx->media()->stats($actor);}
    public function restoreOriginal(ForumAccessPolicy $actor,string $id): array {return $this->tx->media()->restoreOriginal($actor,$id);}
    public function retryImage(ForumAccessPolicy $actor,string $id): array {return $this->tx->media()->retryImage($actor,$id);}
    public function remove(ForumAccessPolicy $actor,string $id): array
    {
        return $this->tx->write(function()use($actor,$id){
            $a=$this->repo->find($id)??throw new ForumException('Вложение не найдено.',404);
            if($a['post_id']!==null){$t=$this->threads->lockForPost($a['post_id'])??throw new ForumException('Вложение не найдено.',404);$p=$this->repo->post($a['post_id'])??throw new ForumException('Вложение не найдено.',404);$this->tx->permissions()->assertWrite($actor,$t['board_id'],$p['author_id']===$actor->userId&&(int)$t['locked']===0?'post:edit_own':'post:edit_any');$actor->assertEdit($p,$t);$this->boards->writable($t['board_id']);}
            elseif($a['message_id']!==null){$this->conversations->lockWrites();$this->tx->permissions()->requireCurrent($actor,'forum:write',true);$m=$this->repo->message($a['message_id'])??throw new ForumException('Вложение не найдено.',404);$c=$this->conversations->membership($m['conversation_id'],$actor->userId);$this->conversations->message($m['conversation_id'],$m['id'],(int)$c['joined_sequence']);if($m['author_id']!==$actor->userId)throw new ForumException('Недостаточно прав.',403);$this->tx->sanctions()->enforce($actor->userId,'conversation');foreach($this->conversations->participants($m['conversation_id']) as $participant){$target=$participant['user_id'];if($target!==$actor->userId&&$this->conversations->blocked($actor->userId,$target))throw new ForumException('Редактирование в этой переписке недоступно.',403);}}
            else{$this->tx->permissions()->requireCurrent($actor,'forum:write',true);if($a['owner_id']!==$actor->userId)throw new ForumException('Вложение не найдено.',404);}
            $locked=$this->repo->lockItem($id);if(!$locked||$locked['post_id']!==$a['post_id']||$locked['message_id']!==$a['message_id'])throw new ForumException('Вложение изменилось.',409);
            if($this->repo->held($id))throw new ForumException('Файл ожидает проверки. Сначала отмените заявку.',409);
            if($a['post_id']!==null||$a['message_id']!==null){
                $kind=$a['post_id']!==null?'post':'message';
                $content=$kind==='post'?$this->repo->postContent($a['post_id']):$this->conversations->message($m['conversation_id'],$m['id'],(int)$c['joined_sequence']);
                if(!$content)throw new ForumException('Сообщение не найдено.',404);
                $this->tx->revisions()->advance($kind,$content,$content['body'],$content['body_format'],$actor->userId,null,'Remove attachment',true);
            }
            $this->repo->delete($id);$this->tx->audit('forum.attachment.delete',$actor->userId,['attachment_id'=>$id]);return ['deleted'=>true];
        });
    }
    public function cleanup(): int {return $this->tx->write(fn()=>$this->repo->cleanup(time()));}
}
