<?php
declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Update\OperationFiles as F;

/** Exact object operations, no shell, no bucket-wide deletion. AWS CLI v2 supports multipart streaming. */
final class OffsiteBackup
{
    private array $dest;
    private ?string $directory=null;
    public function __construct(private readonly string $root,private readonly BackupPolicy $policy,bool $readOnly=false)
    {
        $this->dest=$policy->data['destination'];
        if ($this->dest['type']==='file') {
            $p=$this->dest['directory'];$real=realpath($p);$base=realpath($root);
            if ($real!==$p||$base===false||$real===$base||str_starts_with($real,$base.'/')||!is_dir($real)||(fileperms($real)&0077)!==0) throw new \RuntimeException('Off-site directory must exist outside the installation, be canonical and private (0700)');
            if ($policy->data['require_separate_device']&&stat($real)['dev']===stat($root.'/storage')['dev']) throw new \RuntimeException('Off-site mount is not on a separate device');
            $this->directory=$real.'/'.$policy->data['namespace']; if ($readOnly) { if (!is_dir($this->directory)||is_link($this->directory)) throw new \RuntimeException('Off-site namespace is missing'); } else F::dir($this->directory); if ((fileperms($this->directory)&0077)!==0) throw new \RuntimeException('Off-site namespace must be private');
        }
    }
    private function name(string $id,string $sha): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new \RuntimeException('Invalid managed backup object');
        return $id.'-'.$sha.'.cebackup';
    }
    private function location(string $id,string $sha): string
    {
        $name=$this->name($id,$sha);
        return $this->directory!==null?F::path($this->directory,$name):'s3://'.$this->dest['bucket'].'/'.$this->dest['prefix'].'/'.$this->policy->data['namespace'].'/'.$name;
    }
    public function publish(string $id,string $sha,string $source,int $bytes): void
    {
        if (F::hash($source)!==$sha||filesize($source)!==$bytes) throw new \RuntimeException('Encrypted staging checksum mismatch');
        $target=$this->location($id,$sha);
        if ($this->directory!==null) {
            if (!file_exists($target)) {
                $free=disk_free_space($this->directory); if ($free===false||$free<$bytes+$this->policy->data['min_free_bytes']) throw new \RuntimeException('Insufficient off-site disk space');
                $tmp=$this->directory.'/.upload-'.$id;
                // Only this installation's locked writer uses this unique namespace and ID.
                F::remove($tmp); F::copy($source,$tmp);
                try { if (!link($tmp,$target)) throw new \RuntimeException('Cannot publish off-site object without overwrite'); F::syncDir($this->directory); }
                finally { F::remove($tmp); }
            }
        } else {
            // Content-addressed name; repeating a failed multipart transfer writes identical ciphertext.
            $this->aws(['s3','cp',$source,$target,'--only-show-errors','--no-progress']);
        }
        $this->verify($id,$sha,$bytes);
    }
    public function verify(string $id,string $sha,int $bytes): void
    {
        $target=$this->location($id,$sha);
        if ($this->directory!==null) {
            if (F::hash($target)!==$sha||filesize($target)!==$bytes) throw new \RuntimeException('Off-site readback checksum mismatch');
        } else {
            [$actual,$size]=$this->aws(['s3','cp',$target,'-','--only-show-errors','--no-progress'],$bytes);
            if ($size!==$bytes||!hash_equals($sha,$actual)) throw new \RuntimeException('S3 readback checksum mismatch');
        }
    }
    /** Fetch an exact content-addressed bundle onto a new host; never overwrite an existing file. */
    public function fetch(string $id,string $sha,string $destination): array
    {
        $source=$this->location($id,$sha); $parent=dirname($destination);
        if (!str_starts_with($destination,'/')||realpath($parent)!==$parent||!preg_match('/^[A-Za-z0-9_.-]+$/D',basename($destination))||!is_dir($parent)||(fileperms($parent)&0077)!==0||fileowner($parent)!==posix_geteuid()||file_exists($destination)||is_link($destination)) throw new \RuntimeException('Use a new file in a private canonical destination directory');
        if ($destination===$this->root||str_starts_with($destination,$this->root.'/')) throw new \RuntimeException('Recovery bundle must remain outside application root');
        $tmp=$parent.'/.fetch-'.bin2hex(random_bytes(16));
        try {
            if ($this->directory!==null) F::copy($source,$tmp);
            else {
                // AWS writes only into the operator-owned private directory. Verify bytes before publishing.
                $this->aws(['s3','cp',$source,$tmp,'--only-show-errors','--no-progress']);
                if (!is_file($tmp)||is_link($tmp)||!chmod($tmp,0600)) throw new \RuntimeException('Cannot protect downloaded bundle');
                $h=fopen($tmp,'r+b'); if ($h===false) throw new \RuntimeException('Cannot sync downloaded bundle');
                try { if (!fsync($h)) throw new \RuntimeException('Cannot sync downloaded bundle'); } finally { fclose($h); }
            }
            if (!hash_equals($sha,F::hash($tmp))) throw new \RuntimeException('Off-site recovery checksum mismatch');
            $bytes=filesize($tmp);
            if (!link($tmp,$destination)) throw new \RuntimeException('Cannot publish downloaded bundle without overwrite');
            F::syncDir($parent);
            return ['ok'=>true,'id'=>$id,'bundle_sha256'=>$sha,'bytes'=>$bytes,'file'=>$destination];
        } finally { F::remove($tmp); }
    }
    public function delete(string $id,string $sha): void
    {
        $target=$this->location($id,$sha);
        if ($this->directory!==null) F::remove($target);
        else $this->aws(['s3','rm',$target,'--only-show-errors']);
    }
    private function aws(array $args,?int $maxBytes=null): array
    {
        $command=['aws','--no-cli-pager','--cli-connect-timeout','15','--cli-read-timeout','60'];
        if (isset($this->dest['endpoint'])) array_push($command,'--endpoint-url',$this->dest['endpoint']);
        if (isset($this->dest['region'])) array_push($command,'--region',$this->dest['region']);
        $command=array_merge($command,$args);
        $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($proc)) throw new \RuntimeException('Cannot launch AWS CLI');
        $ctx=hash_init('sha256');$size=0;$failed=false;
        try {
            while (!feof($pipes[1])) {
                $chunk=fread($pipes[1],1048576);if ($chunk===false) throw new \RuntimeException('S3 read failed');
                $size+=strlen($chunk); if ($maxBytes!==null&&$size>$maxBytes) throw new \RuntimeException('S3 object exceeds expected size');
                hash_update($ctx,$chunk);
            }
        } catch (\Throwable $e) {$failed=true;proc_terminate($proc);throw $e;}
        finally {fclose($pipes[1]);$exit=proc_close($proc);if (!$failed&&$exit!==0) throw new \RuntimeException('S3 transfer failed; check AWS CLI credentials, endpoint and storage');}
        return [hash_final($ctx),$size];
    }
}
