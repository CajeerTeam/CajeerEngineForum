<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Security\PublicHttpClient;

/** Stream trusted-origin GitHub release assets without following arbitrary redirects. */
final class GitHubAssetTransport
{
    private const REDIRECT_HOSTS = ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'];

    /** Resume only a ZIP with a known exact length. Redirect/error bodies never enter the file.
     * @param resource $destination
     */
    public function download(string $url, mixed $destination, int $limit, int $timeoutSeconds = 300, bool $resume = false, ?\Closure $progress = null): int
    {
        if (!is_resource($destination) || $limit < 1 || $limit > 2147483648) throw new \InvalidArgumentException('Invalid download destination or byte limit');
        if (!function_exists('curl_init')) throw new \RuntimeException('Secure release download requires ext-curl');
        $offset = $resume ? (int) fstat($destination)['size'] : 0;
        if ($offset > $limit) throw new \RuntimeException('Partial asset exceeds expected size');
        if ($resume && $offset === $limit) return $offset;
        $deadline = hrtime(true) + max(15, min(1800, $timeoutSeconds)) * 1000000000;
        $seen = [];
        for ($step = 0; $step <= 5; $step++) {
            self::validateUrl($url);
            if (isset($seen[$url])) throw new \RuntimeException('GitHub release redirect loop');
            $seen[$url] = true;
            [$host, $port, $ip] = PublicHttpClient::target($url);
            $remaining = (int) ceil(($deadline - hrtime(true)) / 1000000000);
            if ($remaining <= 0) throw new \RuntimeException('GitHub asset download deadline exceeded');
            $status = 0; $headers = []; $size = $offset; $allowed = false; $invalid = false;
            $ch = curl_init($url);
            if ($ch === false) throw new \RuntimeException('Cannot initialize release download');
            try {
                $requestHeaders = ['User-Agent: CajeerEngine-Secure-Release-Downloader', 'Accept: application/octet-stream', 'Accept-Encoding: identity'];
                if ($offset > 0) $requestHeaders[] = 'Range: bytes=' . $offset . '-';
                $opts = [
                    CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $requestHeaders,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                    CURLOPT_CONNECTTIMEOUT => min(15, $remaining), CURLOPT_TIMEOUT => $remaining,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use ($destination, $offset, $limit, &$status, &$headers, &$size, &$allowed, &$invalid): int {
                        if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match)) {
                            $status = (int) $match[1]; $headers = []; $allowed = false;
                        } elseif (trim($header) === '') {
                            if (!$allowed && in_array($status, [200, 206], true)) {
                                try {
                                    $start = self::responseOffset($status, $headers, $offset, $limit);
                                    if (!ftruncate($destination, $start) || fseek($destination, $start) !== 0) throw new \RuntimeException('Cannot position partial asset');
                                    $size = $start; $allowed = true;
                                } catch (\Throwable) { $invalid = true; return 0; }
                            }
                        } elseif (str_contains($header, ':')) {
                            [$key, $value] = explode(':', $header, 2); $key = strtolower(trim($key));
                            if (in_array($key, ['location','content-range','content-encoding'], true)) {
                                if (isset($headers[$key])) { $invalid = true; return 0; }
                                $headers[$key] = trim($value);
                            }
                        }
                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use ($destination, &$size, $limit, &$allowed, $progress): int {
                        $length = strlen($bytes);
                        if (!$allowed) return $length;
                        if ($size > $limit - $length || fwrite($destination, $bytes) !== $length) return 0;
                        $size += $length;
                        if ($progress !== null) $progress($size);
                        return $length;
                    },
                ];
                $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
                $success = curl_setopt_array($ch, $opts) && curl_exec($ch) !== false;
                if (!fflush($destination) || !fsync($destination)) throw new \RuntimeException('Cannot persist release bytes');
                if ($invalid) throw new \RuntimeException('Invalid GitHub asset response headers');
                if (!$success) throw new \RuntimeException('GitHub release download failed, exceeded byte limit, or timed out');
            } finally { curl_close($ch); }
            if ($allowed && in_array($status, [200,206], true)) {
                if ($size < 1 || ($resume && $size !== $limit)) throw new \RuntimeException('Empty or incomplete GitHub asset');
                return $size;
            }
            if (in_array($status, [301,302,303,307,308], true)) {
                $location = $headers['location'] ?? null;
                if (!is_string($location) || $location === '' || $step === 5) throw new \RuntimeException('Invalid GitHub asset redirect');
                self::validateUrl($location); $url = $location; continue;
            }
            // A server may refuse ranges. Reset only after 416; the next poll starts fresh.
            if ($status === 416 && $offset > 0) {
                if (!ftruncate($destination, 0) || !fflush($destination) || !fsync($destination)) throw new \RuntimeException('Cannot reset refused range');
            }
            throw new \RuntimeException('GitHub asset returned HTTP ' . $status);
        }
        throw new \RuntimeException('Too many GitHub asset redirects');
    }

    /** Validate before writing any body bytes. A full 200 safely replaces a partial. */
    public static function responseOffset(int $status, array $headers, int $offset, int $expected): int
    {
        if (strtolower($headers['content-encoding'] ?? 'identity') !== 'identity') throw new \RuntimeException('Encoded asset response is unsupported');
        if ($status === 200 && !isset($headers['content-range'])) return 0;
        if ($status === 206 && $offset > 0 && ($headers['content-range'] ?? '') === 'bytes '.$offset.'-'.($expected-1).'/'.$expected) return $offset;
        throw new \RuntimeException('Unexpected asset range');
    }

    public static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), self::REDIRECT_HOSTS, true)
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment'])
            || isset($parts['port']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \RuntimeException('Untrusted GitHub asset URL');
        }
        if (strtolower((string) ($parts['host'] ?? '')) === 'github.com'
            && !str_starts_with(strtolower((string) ($parts['path'] ?? '')), '/'.strtolower(GitHubEndpoints::REPOSITORY).'/releases/download/')) {
            throw new \RuntimeException('Untrusted GitHub repository asset path');
        }
    }
}
