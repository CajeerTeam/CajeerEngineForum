<?php
declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Update\OperationFiles as F;

/** Operator-owned policy. No credentials, commands or paths from HTTP. UTC retention buckets. */
final readonly class BackupPolicy
{
    private function __construct(public array $data) {}
    public static function load(string $root,string $path): self
    {
        $real=realpath($path); $base=realpath($root);
        if (!str_starts_with($path,'/')||$real!==$path||$base===false||str_starts_with($real,$base.'/')||!is_file($real)||(fileperms($real)&0077)!==0) throw new \RuntimeException('Backup policy must be an absolute external regular file with mode 0600');
        return self::parse(F::read($real));
    }
    public static function parse(array $d): self
    {
        $defaults=['interval_seconds'=>86400,'lock_wait_seconds'=>15,'daily'=>14,'weekly'=>8,'monthly'=>12,'min_free_bytes'=>67108864,'prune_offsite'=>false,'require_separate_device'=>true];
        if (array_diff(array_keys($d),array_merge(array_keys($defaults),['destination','namespace']))) throw new \RuntimeException('Unknown backup policy option');
        $d+=$defaults;
        foreach (['interval_seconds'=>[300,2678400],'lock_wait_seconds'=>[0,300],'daily'=>[1,365],'weekly'=>[0,104],'monthly'=>[0,120],'min_free_bytes'=>[0,1099511627776]] as $k=>[$min,$max]) if (!is_int($d[$k])||$d[$k]<$min||$d[$k]>$max) throw new \RuntimeException('Invalid backup policy '.$k);
        foreach (['prune_offsite','require_separate_device'] as $k) if (!is_bool($d[$k])) throw new \RuntimeException('Invalid backup policy '.$k);
        if (!is_string($d['namespace']??null)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$d['namespace'])) throw new \RuntimeException('Provide a unique backup namespace');
        $dest=$d['destination']??null;
        if (!is_array($dest)||!in_array($dest['type']??null,['file','s3'],true)) throw new \RuntimeException('Select file or s3 off-site destination');
        if ($dest['type']==='file') {
            if (array_diff(array_keys($dest),['type','directory'])||!is_string($dest['directory']??null)||!str_starts_with($dest['directory'],'/')) throw new \RuntimeException('Provide absolute off-site directory');
        } else {
            if (array_diff(array_keys($dest),['type','bucket','prefix','endpoint','region'])||!is_string($dest['bucket']??null)||!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$dest['bucket'])||str_contains($dest['bucket'],'..')||!is_string($dest['prefix']??null)||!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$dest['prefix'])) throw new \RuntimeException('Invalid S3 backup bucket/prefix');
            if (isset($dest['endpoint'])) { $u=is_string($dest['endpoint'])?parse_url($dest['endpoint']):false; if (!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||!in_array($u['path']??'',['','/'],true)) throw new \RuntimeException('S3 backup endpoint must use HTTPS'); }
            if (isset($dest['region'])&&(!is_string($dest['region'])||!preg_match('/^[a-z0-9-]{1,40}$/D',$dest['region']))) throw new \RuntimeException('Invalid S3 region');
        }
        return new self($d);
    }
    public function identity(): string { $dest=$this->data['destination']; ksort($dest); return hash('sha256',json_encode([$this->data['namespace'],$dest],JSON_THROW_ON_ERROR)); }
    /** Retain newest snapshot in each selected calendar bucket, plus two newest verified copies. */
    public function keep(array $records,int $now): array
    {
        $keep=[];$buckets=[];$n=0;
        $today=new \DateTimeImmutable(gmdate('Y-m-d',$now).'T00:00:00Z');
        $cutoffs=['daily'=>$today->modify('-'.($this->data['daily']-1).' days')->getTimestamp(), 'weekly'=>$today->modify('monday this week')->modify('-'.max(0,$this->data['weekly']-1).' weeks')->getTimestamp(), 'monthly'=>$today->modify('first day of this month')->modify('-'.max(0,$this->data['monthly']-1).' months')->getTimestamp()];
        // Input is durable insertion order; reverse gives latest capture first, including equal-second captures.
        foreach (array_reverse($records,true) as $id=>$r) {
            if (($r['state']??'')!=='verified') continue;
            $t=$r['created_at']; if (++$n<=2||$t>$now) $keep[$id]=true;
            foreach (['daily'=>'Y-m-d','weekly'=>'o-W','monthly'=>'Y-m'] as $kind=>$format) {
                if ($this->data[$kind]===0||$t<$cutoffs[$kind]) continue;
                $bucket=$kind.':'.gmdate($format,$t); if (!isset($buckets[$bucket])) {$buckets[$bucket]=true;$keep[$id]=true;}
            }
        }
        return $keep;
    }
}
