<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Host-provisioned trust roots. A release cannot add or revoke its own keys. */
final class ReleaseTrust
{
    private function __construct(private array $keys, private ?string $legacy) {}

    public static function configured(string $root): self
    {
        $ring=(string)getenv('UPDATE_TRUST_POLICY_FILE');
        if ($ring!=='') {
            // Root-owned ancestors, including the policy itself; no links or writable directories.
            if (!str_starts_with($ring,'/etc/') || realpath($ring)!==$ring || str_starts_with($ring,rtrim(realpath($root)?:$root,'/').'/')) throw new \RuntimeException('Trust policy must be external canonical /etc file');
            for ($p=$ring;$p!=='/';$p=dirname($p)) {
                clearstatcache(true,$p); $st=@lstat($p);
                if (!$st || $st['uid']!==0 || ($st['mode']&0022)!==0 || is_link($p)) throw new \RuntimeException('Unsafe release trust policy ownership');
            }
            if (!is_file($ring)||filesize($ring)>32768) throw new \RuntimeException('Invalid release trust policy file');
            return self::fromPolicy(OperationFiles::read($ring));
        }
        $p=(string)getenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE');
        $real=realpath($p);
        if ($p===''||!str_starts_with($p,'/')||$real!==$p||!is_file($p)||is_link($p)||(fileperms($p)&0022)!==0||str_starts_with($p,rtrim(realpath($root)?:$root,'/').'/')) throw new \RuntimeException('Provision UPDATE_TRUSTED_PUBLIC_KEY_FILE');
        // Legacy paths can be provisioned by a dedicated operator (including /tmp test fixtures).
        // Reject writable ancestors except root-owned sticky directories; reject symlink aliases above.
        for ($parent=dirname($p);$parent!=='/';$parent=dirname($parent)) {
            $st=@lstat($parent);
            if (!$st || (($st['mode']&0022)!==0 && !(($st['mode']&01000)!==0 && $st['uid']===0))) throw new \RuntimeException('Unsafe trusted key directory');
        }
        return new self([], $p);
    }

    /** Validates the entire policy, including inactive/revoked entries. */
    public static function fromPolicy(array $policy): self
    {
        $entries=$policy['keys']??null;
        if (($policy['schema']??null)!==1||!is_array($entries)||!array_is_list($entries)||count($entries)<1||count($entries)>32) throw new \RuntimeException('Invalid release trust policy');
        $keys=[];
        foreach ($entries as $entry) {
            if (!is_array($entry)||!is_string($entry['public_key']??null)||!is_bool($entry['revoked']??null)) throw new \RuntimeException('Invalid trusted release key');
            $public=base64_decode($entry['public_key'],true);
            if (!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new \RuntimeException('Invalid trusted release key bytes');
            $id=hash('sha256',$public);
            if (($entry['key_id']??null)!==$id||isset($keys[$id])) throw new \RuntimeException('Invalid or duplicate release key ID');
            $from=self::timestamp($entry['not_before']??null); $until=self::timestamp($entry['not_after']??null);
            if ($until<=$from) throw new \RuntimeException('Invalid release key validity');
            $keys[$id]=['public'=>$public,'from'=>$from,'until'=>$until,'revoked'=>$entry['revoked']];
        }
        return new self($keys,null);
    }

    public static function timestamp(mixed $value): int
    {
        if (!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)) throw new \RuntimeException('Invalid UTC trust timestamp');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
        if (!$date||$date->format('Y-m-d\TH:i:s\Z')!==$value) throw new \RuntimeException('Invalid UTC trust timestamp');
        return $date->getTimestamp();
    }

    public function verify(string $digest,string $signature,?string $version=null,?string $mode=null): array
    {
        if ($this->legacy!==null) $verified=ReleaseSignature::verifyDigest($digest,$signature,$this->legacy);
        else {
            $data=ReleaseSignature::readSignature($signature);
            $id=$data['key_id']??null;
            $key=is_string($id)?($this->keys[$id]??null):null; $now=time();
            if ($key===null||$key['revoked']||$now<$key['from']||$now>=$key['until']) throw new \RuntimeException('Release signing key unknown, revoked or outside validity');
            $verified=ReleaseSignature::verifyData($digest,$data,$key['public']);
            if (isset($verified['authorization']) && (self::timestamp($verified['authorization']['issued_at'])<$key['from'] || self::timestamp($verified['authorization']['issued_at'])>=$key['until'])) throw new \RuntimeException('Release authorization issued outside key validity');
        }
        if ($version!==null) self::identity($verified,$version,$mode);
        return $verified;
    }

    public static function requiresAuthorization(string $version): bool
    {
        return version_compare(explode('-', $version, 2)[0],'1.5.10','>=');
    }

    public static function identity(array $verified,string $version,?string $mode): void
    {
        $auth=$verified['authorization']??null;
        if ($auth===null) {
            if (self::requiresAuthorization($version)) throw new \RuntimeException('Missing signed release authorization');
            return;
        }
        if ($auth['version']!==$version||($mode!==null&&$auth['mode']!==$mode)) throw new \RuntimeException('Signed release authorization identity mismatch');
    }

    /** Pin authenticated distribution bytes, independently of the mutable GitHub catalog. */
    public static function pin(string $root,array $verified): void
    {
        if (($verified['ok']??false)!==true||($verified['mode']??null)!=='dist') throw new \RuntimeException('Cannot pin unverified distribution');
        $version=$verified['version']??null; $sha=$verified['sha256']??null;
        if (!is_string($version)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$version)||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new \RuntimeException('Invalid release pin identity');
        $dir=OperationFiles::path($root,'storage/app/updates/trust-pins');
        OperationFiles::dir($dir,0700);
        $lock=OperationLock::openLockFile($dir.'/.lock',0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock release pins');
            $path=$dir.'/'.hash('sha256',$version).'.json';
            if (file_exists($path)||is_link($path)) {
                $old=OperationFiles::read($path);
                if (($old['version']??null)!==$version||($old['sha256']??null)!==$sha) throw new \RuntimeException('Release digest changed after first verification; operator review required');
                return;
            }
            OperationFiles::json($path,['version'=>$version,'sha256'=>$sha,'key_id'=>$verified['key_id']??null,'first_verified_at'=>gmdate(DATE_ATOM)]);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
