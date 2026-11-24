<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

final class AbuseIdentity
{
    /** Collapse IPv4-mapped IPv6 and IPv6 /64 rotation; never trust forwarded headers directly. */
    public static function network(?string $ip): string
    {
        $packed = is_string($ip) ? @inet_pton($ip) : false;
        if ($packed === false) return 'unknown';
        if (strlen($packed) === 16 && substr($packed,0,12) === str_repeat("\0",10)."\xff\xff") $packed = substr($packed,12);
        if (strlen($packed) === 16) return inet_ntop(substr($packed,0,8).str_repeat("\0",8)).'/64';
        return inet_ntop($packed) ?: 'unknown';
    }
    public static function account(string $email): string
    {
        $email = function_exists('mb_strtolower') ? mb_strtolower(trim($email),'UTF-8') : strtolower(trim($email));
        $key = getenv('APP_KEY');
        return is_string($key) && strlen($key)>=32 ? hash_hmac('sha256','login-account:v1:'.$email,$key) : hash('sha256',$email);
    }
}
