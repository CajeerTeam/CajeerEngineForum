<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final class TwoFactorTotp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $secret .= self::ALPHABET[bindec($chunk)];
        }
        return $secret;
    }

    public function code(#[\SensitiveParameter] string $secret, ?int $time = null, int $digits = 6, int $period = 30): string
    {
        $time ??= time();
        $counter = intdiv($time, $period);
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $window = 1): bool
    {
        return $this->matchingStep($secret, $code, $window) !== null;
    }

    /** Return the verified 30-second TOTP step for atomic replay protection.
     * Do not use a boolean-only check when establishing or raising authentication assurance.
     */
    public function matchingStep(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $window = 1): ?int
    {
        if ($window < 0 || $window > 2 || preg_match('/^[0-9]{6}$/D', $code) !== 1) return null;
        if (preg_match('/^[A-Z2-7]{16,128}$/D', strtoupper($secret)) !== 1) return null;
        $currentStep = intdiv(time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($step >= 0 && hash_equals($this->code($secret, $step * 30), $code)) return $step;
        }
        return null;
    }

    /** @return list<string> */
    public function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }
        return $codes;
    }

    /** @param list<string> $codes @return list<string> */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT), $codes);
    }

    private function base32Decode(string $secret): string
    {
        $secret = preg_replace('/[^A-Z2-7]/', '', strtoupper($secret)) ?? '';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                continue;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}
