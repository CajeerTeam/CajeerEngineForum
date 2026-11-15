<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\Content\ForumImageProcessor;
use CajeerEngine\Forum\Repository\{AttachmentRepository,ForumMediaRepository};
use CajeerEngine\Storage\S3CompatibleDisk;
use CajeerEngine\Forum\Security\ForumAccessPolicy;

final readonly class ForumMediaService
{
    public function __construct(private ForumTransaction $tx,private AttachmentRepository $files,private ForumMediaRepository $repo) {}
    public function enqueue(array $a): void {if(in_array($a['mime'],['image/jpeg','image/png','image/webp'],true))$this->repo->enqueue($a['id']);}
    public function decorate(array $items): array
    {
        $map=[];foreach($this->repo->variants(array_column($items,'id')) as $v){$id=$v['attachment_id'];$name=$v['variant'];$map[$id][$name]=['url'=>'/api/v1/forum/attachments/'.$id.'/image/'.$name,'width'=>(int)$v['width'],'height'=>(int)$v['height'],'mime'=>$v['mime'],'size_bytes'=>(int)$v['size_bytes']];}
        foreach($items as &$a)$a['variants']=(object)($map[$a['id']]??[]);unset($a);return $items;
    }
    public function image(string $id,string $name): array
    {
        if(!in_array($name,ForumImageProcessor::NAMES,true))throw new \CajeerEngine\Forum\ForumException('Изображение не найдено.',404);
        $v=$this->repo->variant($id,$name);if(!$v)throw new \CajeerEngine\Forum\ForumException('Изображение ещё не готово.',404);
        $data=base64_decode($v['file_data'],true);if($data===false||!ForumImageProcessor::valid($v,$data))throw new \CajeerEngine\Forum\ForumException('Изображение повреждено.',503);return ['data'=>$data,'mime'=>$v['mime'],'size_bytes'=>strlen($data)];
    }
    public static function variantQuota(): int {return max(1,min(2048,(int)(getenv('FORUM_MEDIA_VARIANT_QUOTA_MB')?:100)))*1048576;}
    public function usage(string $user): int {return $this->repo->variantUsage($user);}
    public function stats(ForumAccessPolicy $actor): array
    {return $this->tx->read(function()use($actor){$this->tx->permissions()->requireCurrent($actor,'*');$enabled=getenv('FORUM_MEDIA_S3_REPLICA')==='true';$configured=false;$mismatched=0;try{$config=self::replicaConfig();if($config){$configured=true;$mismatched=$this->repo->mismatchedReplicas($config['destination']);}}catch(\Throwable $e){}return $this->repo->stats()+['processor_available'=>ForumImageProcessor::available(),'s3_enabled'=>$enabled,'s3_configured'=>$configured,'s3_mismatched'=>$mismatched];});}
    public function retryImage(ForumAccessPolicy $actor,string $id): array
    {return $this->tx->write(function()use($actor,$id){$this->tx->permissions()->requireCurrent($actor,'*',true);if(!$this->files->lockItem($id)||!$this->repo->imageState($id))throw new \CajeerEngine\Forum\ForumException('Изображение не найдено.',404);$this->repo->retryImage($id);$this->tx->audit('forum.media.retry',$actor->userId,['attachment_id'=>$id]);return ['queued'=>true];});}
    private function bytes(array $a): string
    { $data=base64_decode($this->files->bytes($a['id'])['file_data']??'',true);if($data===false||strlen($data)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash('sha256',$data)))throw new \RuntimeException('Original integrity mismatch');return $data; }
    /** Work outside the write transaction; the attachment lock makes the final publish atomic. */
    public function processImage(): string
    {
        if(!ForumImageProcessor::available())return 'disabled';$job=$this->repo->nextImage(time());if(!$job)return 'idle';$id=$job['attachment_id'];
        // A durable reservation with a random fence; killed workers become eligible after five minutes.
        $token=bin2hex(random_bytes(32));$lease=$this->tx->write(function()use($id,$token){if(!$this->files->lockItem($id))return 0;$now=time();$lease=$now+300;return $this->repo->claimImage($id,$now,$lease,$token)?$lease:0;});if(!$lease)return 'idle';
        try {
            $a=$this->tx->read(fn()=>$this->files->find($id));if(!$a)return 'idle';$data=$this->tx->read(fn()=>$this->bytes($a));$variants=ForumImageProcessor::generate($a,$data);
            return $this->tx->write(function()use($id,$a,$variants,$lease,$token){
                if(!$this->repo->lockOwner($a['owner_id']))return 'idle';$locked=$this->files->lockItem($id);$state=$this->repo->imageState($id);
                if(!$locked||$locked['sha256']!==$a['sha256']||!$state||$state['state']!=='pending'||$state['lease_token']!==$token||(int)$state['due_at']!==$lease||$lease<=time())return 'idle';
                if($this->repo->variantUsage($a['owner_id'])-$this->repo->attachmentVariantUsage($id)+array_sum(array_column($variants,'size_bytes'))>self::variantQuota()){$this->repo->failImage($id,5,time());return 'failed';}
                $this->repo->store($id,$variants);return 'ready';
            });
        }catch(\Throwable $e){return $this->tx->write(function()use($id,$lease,$token){if(!$this->files->lockItem($id))return 'idle';$state=$this->repo->imageState($id);if(!$state||$state['state']!=='pending'||$state['lease_token']!==$token||(int)$state['due_at']!==$lease)return 'idle';$attempt=(int)$state['attempts']+1;$this->repo->failImage($id,$attempt,time()+min(3600,60*2**min(6,$attempt-1)));return 'failed';});}
    }
    /** Configuration is intentionally opt-in and isolated from the CMS replica prefix. */
    public static function replicaConfig(): ?array
    {
        if(getenv('FORUM_MEDIA_S3_REPLICA')!=='true')return null;
        $config=(require dirname(__DIR__,3).'/config/storage.php')['disks']['s3'];$prefix=(string)getenv('FORUM_MEDIA_S3_PREFIX');$parts=parse_url((string)$config['endpoint']);
        if(!preg_match('~\A[a-zA-Z0-9_-]{1,80}\z~D',$prefix)||!is_array($parts)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(($parts['scheme']??'')!=='https'&&!(($parts['scheme']??'')==='http'&&in_array(getenv('APP_ENV'),['local','testing'],true)&&getenv('MEDIA_S3_ALLOW_HTTP')==='true')))throw new \RuntimeException('Invalid forum S3 endpoint or instance prefix');
        $destination=hash('sha256',json_encode([$config['endpoint'],$config['bucket'],$config['region'],$config['path_style'],$prefix],JSON_THROW_ON_ERROR));
        $config['timeout']=10;return ['disk'=>new S3CompatibleDisk($config),'destination'=>$destination,'prefix'=>'forum-media/'.$prefix];
    }
    public function restoreOriginal(ForumAccessPolicy $actor,string $id): array
    {
        $config=self::replicaConfig();if(!$config)throw new \CajeerEngine\Forum\ForumException('S3 реплика не настроена.',503);
        return $this->tx->write(function()use($actor,$id,$config){
            $this->tx->permissions()->requireCurrent($actor,'*',true);$a=$this->files->lockItem($id);$r=$this->repo->lockReplica($id);
            if(!$a||!$r||$r['destination']!==$config['destination']||$r['object_key']!==$config['prefix'].'/'.$id.'/'.$a['sha256'])throw new \CajeerEngine\Forum\ForumException('Реплика не найдена.',404);
            try{$bytes=$config['disk']->getBounded($r['object_key'],(int)$a['size_bytes']);}catch(\Throwable $e){throw new \CajeerEngine\Forum\ForumException('Реплика недоступна.',503);}
            if(strlen($bytes)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash('sha256',$bytes)))throw new \CajeerEngine\Forum\ForumException('Контрольная сумма реплики не совпадает.',503);
            $this->files->restoreBytes($id,$bytes);$this->repo->replicaResult($id,'ready',0,time()+86400);if($this->repo->imageState($id))$this->repo->retryImage($id);
            $this->tx->audit('forum.media.restore',$actor->userId,['attachment_id'=>$id]);return ['restored'=>true];
        });
    }
    /** Original replicas are durable and checksum-verified. Never publish a direct bucket URL. */
    public function syncReplica(): string
    {
        $config=self::replicaConfig();if(!$config)return 'disabled';$destination=$config['destination'];$disk=$config['disk'];
        // Commit the ledger BEFORE issuing a PUT: a crash can never lose the remote object key.
        $this->tx->write(function()use($config,$destination){$a=$this->repo->missingReplica();if(!$a)return;$locked=$this->files->lockItem($a['id']);if(!$locked||$this->repo->lockReplica($a['id']))return;$this->repo->addReplica($a['id'],$destination,$config['prefix'].'/'.$a['id'].'/'.$a['sha256']);});
        $job=$this->repo->nextReplica($destination,time());if(!$job)return 'idle';$id=$job['attachment_id'];
        return $this->tx->write(function()use($id,$destination,$disk,$config){$a=$this->files->lockItem($id);$r=$this->repo->lockReplica($id);if(!$r||$r['destination']!==$destination||((int)$r['due_at']>time()&&($a||$r['state']!=='ready')))return 'idle';
            $key=$r['object_key'];if(!preg_match('~\A'.preg_quote($config['prefix'],'~').'/'.preg_quote($id,'~').'/[a-f0-9]{64}\z~D',$key))throw new \RuntimeException('Invalid replica object key');
            try {
                if(!$a){$disk->delete($key);$this->repo->deleteReplica($id);return 'deleted';}
                if($key!==$config['prefix'].'/'.$id.'/'.$a['sha256'])throw new \RuntimeException('Replica key mismatch');
                $bytes=$this->bytes($a);$valid=false;
                if($r['state']==='ready')try{$remote=$disk->getBounded($key,(int)$a['size_bytes']);$valid=strlen($remote)===(int)$a['size_bytes']&&hash_equals($a['sha256'],hash('sha256',$remote));}catch(\RuntimeException $e){if(!str_contains($e->getMessage(),'HTTP 404')&&!str_contains($e->getMessage(),'exceeds byte limit'))throw $e;}
                if(!$valid){$disk->put($key,$bytes);$remote=$disk->getBounded($key,(int)$a['size_bytes']);if(strlen($remote)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash('sha256',$remote)))throw new \RuntimeException('Replica verification failed');}
                $this->repo->replicaResult($id,'ready',0,time()+86400);return 'ready';
            }catch(\Throwable $e){$attempt=min(100,(int)$r['attempts']+1);$this->repo->replicaResult($id,'pending',$attempt,time()+min(3600,60*2**min(6,$attempt-1)));return 'failed';}
        });
    }
}
