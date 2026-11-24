<?php
declare(strict_types=1);
namespace CajeerEngine\Audit;

/** Shared bounded sanitization for SQL and file audit sinks. Preserve IDs and policy diffs. */
final class AuditContext
{
    public static function sanitize(array $context): array
    {
        $budget = 512;
        return self::walk($context, 0, $budget);
    }
    private static function walk(array $input, int $depth, int &$budget): array
    {
        if ($depth >= 8) return ['_truncated'=>true];
        $out = [];
        foreach ($input as $key=>$value) {
            if (--$budget < 0) { $out['_truncated'] = true; break; }
            if (is_string($key)) {
                // Accept only structured field names, not dynamic emails/URLs/credentials as keys.
                if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $key)) continue;
                $normalized = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
                if (preg_match('/(?:^|[_.:-])(?:password|passwd|secrets?|credentials?|authorization|cookies?|ciphertext|api_key|access_key|private_key|recovery_code|recovery_codes|csrf|otp|token|email|ip_address|user_agent|prompt|body|payload|transcript|message_content)(?:$|[_.:-])/', $normalized)
                    && !in_array($normalized,['token_id','old_token_id','email_sha256','password_min_length','password_changed_at','token_count','token_limit','token_budget'],true)) {
                    $out[$key] = '[redacted]'; continue;
                }
            }
            if (is_array($value)) $out[$key] = self::walk($value, $depth+1, $budget);
            elseif (is_string($value)) {
                // Free-form text can contain arbitrary credentials. Keep bounded metadata,
                // suppress identifiable e-mail, common secret formats and credentialed URLs.
                $out[$key] = strlen($value) > 512 || preg_match('~[\x00-\x1f\x7f]|[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:bearer|basic)\s+\S+|(?:sk-|ce_)[a-z0-9_-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|(?:password|secret|token|api[_-]?key)\s*[:=]\s*\S+|https?://[^\s]+[?@]~i', $value) ? '[redacted]' : $value;
            } elseif (is_null($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) $out[$key] = $value;
        }
        return $out;
    }
    public static function network(?string $ip): ?string
    {
        if ($ip === null || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $packed = inet_pton($ip);
        if ($packed === false) return null;
        if (strlen($packed) === 16 && substr($packed,0,12) === str_repeat("\0",10)."\xff\xff") $packed = substr($packed,12);
        // Return a host-form masked address, portable across INET and VARCHAR columns.
        return inet_ntop(strlen($packed) === 4 ? substr($packed,0,3)."\0" : substr($packed,0,6).str_repeat("\0",10)) ?: null;
    }
}
