<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Ed25519 detached signature over the exact ZIP digest (domain-separated). Keys are provisioned out of band. */
final class ReleaseSignature
{
    private const AUTH_PREFIX = "CajeerEngine Forum release authorization v1\n";
    private const PREFIX = "CajeerEngine release ZIP SHA256 v1\n";
    public static function sign(string $zip, string $secretFile): array
    {
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('ext-sodium required');
        if ((fileperms($secretFile) & 0077) !== 0) throw new \RuntimeException('Signing key must be private (0600)');
        $key = self::key($secretFile, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        try {
            $sha = OperationFiles::hash($zip);
            $public = sodium_crypto_sign_publickey_from_secretkey($key);
            $data = ['format'=>'cajeerengine.release-signature.v1','key_id'=>hash('sha256',$public),'sha256'=>$sha,'signature'=>base64_encode(sodium_crypto_sign_detached(self::PREFIX.$sha,$key))];
            // Retain v1 signature for the 1.5.9 updater; new clients additionally verify
            // signed, time-bounded repository/version/mode authorization.
            $archive=null;
            if (file_get_contents($zip,false,null,0,4)==="PK\x03\x04") $archive=new \PharData($zip);
            $manifestName='cajeerengine/release-manifest.json';
            if ($archive!==null && isset($archive[$manifestName])) {
                $manifest=json_decode($archive[$manifestName]->getContent(),true,512,JSON_THROW_ON_ERROR);
                if (($manifest['edition']??null)==='forum' && ReleaseTrust::requiresAuthorization((string)($manifest['version']??'0'))) {
                    $data['authorization']=self::authorize($sha,$manifest['version'],$manifest['mode'],$key,time());
                }
            }
            unset($archive);
            OperationFiles::json($zip.'.sig', $data, 0644);
            OperationFiles::write($zip.'.sha256', $sha.'  '.basename($zip)."\n", 0644);
            return ['ok'=>true,'sha256'=>$sha,'signature'=>$zip.'.sig','key_id'=>$data['key_id']];
        } finally { sodium_memzero($key); }
    }
    public static function verify(string $zip, string $signatureFile, string $publicFile, ?string $expected = null): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new \RuntimeException('ext-sodium required');
        $sha = OperationFiles::hash($zip);
        if ($expected !== null && !hash_equals($sha,strtolower($expected))) throw new \RuntimeException('Release signature or SHA256 mismatch');
        return self::verifyDigest($sha,$signatureFile,$publicFile);
    }
    public static function verifyDigest(string $sha, string $signatureFile, string $publicFile): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new \RuntimeException('ext-sodium required');
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new \RuntimeException('Invalid release digest');
        $public = self::key($publicFile, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        return self::verifyData($sha,self::readSignature($signatureFile),$public);
    }
    public static function readSignature(string $signatureFile): array
    {
        if (!is_file($signatureFile)||is_link($signatureFile)||filesize($signatureFile)>8192) throw new \RuntimeException('Invalid signature file');
        return OperationFiles::read($signatureFile);
    }
    public static function verifyData(string $sha,array $data,string $public): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new \RuntimeException('Invalid release digest or public key');
        $sig = is_string($data['signature']??null)?base64_decode($data['signature'], true):false;
        if (($data['format']??null)!=='cajeerengine.release-signature.v1' || ($data['key_id']??null)!==hash('sha256',$public) || ($data['sha256']??null)!==$sha || !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig,self::PREFIX.$sha,$public)) throw new \RuntimeException('Release signature or SHA256 mismatch');
        $result=['sha256'=>$sha,'key_id'=>$data['key_id']];
        if (array_key_exists('authorization',$data)) {
            $a=$data['authorization'];
            if (!is_array($a)) throw new \RuntimeException('Invalid signed release authorization');
            $payload=self::authorizationPayload($sha,$data['key_id'],$a);
            $issued=ReleaseTrust::timestamp($a['issued_at']??null); $expires=ReleaseTrust::timestamp($a['expires_at']??null);
            $asig=is_string($a['signature']??null)?base64_decode($a['signature'],true):false;
            if (!is_string($asig)||strlen($asig)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($asig,$payload,$public)||$expires<=$issued||$expires-$issued>90*86400||$issued>time()+300||$expires<=time()) throw new \RuntimeException('Release authorization invalid, expired or not yet valid');
            $result['authorization']=$a;
        }
        return $result;
    }
    public static function authorize(string $sha,string $version,string $mode,#[\SensitiveParameter] string $secret,int $issued): array
    {
        $a=['version'=>$version,'mode'=>$mode,'issued_at'=>gmdate('Y-m-d\TH:i:s\Z',$issued),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$issued+90*86400)];
        $id=hash('sha256',sodium_crypto_sign_publickey_from_secretkey($secret));
        $a['signature']=base64_encode(sodium_crypto_sign_detached(self::authorizationPayload($sha,$id,$a),$secret));
        return $a;
    }
    private static function authorizationPayload(string $sha,string $id,array $a): string
    {
        if (!is_string($a['version']??null)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$a['version'])||!in_array($a['mode']??null,['source','dist'],true)) throw new \RuntimeException('Invalid release authorization identity');
        ReleaseTrust::timestamp($a['issued_at']??null); ReleaseTrust::timestamp($a['expires_at']??null);
        return self::AUTH_PREFIX.implode("\n",['CajeerTeam/CajeerEngineForum',$id,$sha,$a['version'],$a['mode'],$a['issued_at'],$a['expires_at']]);
    }
    public static function generate(string $secretFile, string $publicFile): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) throw new \RuntimeException('ext-sodium required');
        if (file_exists($secretFile) || is_link($secretFile) || file_exists($publicFile) || is_link($publicFile) || $secretFile===$publicFile) throw new \RuntimeException('Refusing to overwrite signing keys');
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair); $public = sodium_crypto_sign_publickey($pair);
        try { OperationFiles::write($secretFile,base64_encode($secret)."\n"); OperationFiles::write($publicFile,base64_encode($public)."\n",0644); }
        finally { sodium_memzero($secret); sodium_memzero($pair); }
        return ['ok'=>true,'key_id'=>hash('sha256',$public),'public_key'=>$publicFile];
    }
    private static function key(string $path, int $size): string
    {
        if (!is_file($path) || is_link($path) || filesize($path)>4096) throw new \RuntimeException('Trusted key file missing');
        $key=base64_decode(trim((string)file_get_contents($path)),true);
        if (!is_string($key)||strlen($key)!==$size) throw new \RuntimeException('Invalid Ed25519 key');
        return $key;
    }
}
