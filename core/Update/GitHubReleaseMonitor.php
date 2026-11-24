<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Security\SecretEnvironment;
use CajeerEngine\Support\JsonFile;

/** Conditional, bounded discovery. A cached catalog never authorizes installation. */
final readonly class GitHubReleaseMonitor
{
    private const MAX_PAGES = 10;
    private const MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(private ?string $rootPath, private ?\Closure $transport = null,
        private int $timeoutSeconds = 5, private ?\Closure $sleep = null, private ?\Closure $clock = null) {}

    private function now(): int { return $this->clock !== null ? ($this->clock)() : time(); }

    public function fetch(): array
    {
        $lock = null;
        try {
            $token = $this->token();
            $cache = $this->readCache($token);
            if ($this->rootPath !== null) {
                $lockPath = OperationFiles::path($this->rootPath, 'storage/app/updates/github-release-cache.lock');
                OperationFiles::dir(dirname($lockPath), 0770);
                $lock = OperationLock::openLockFile($lockPath);
                if (!flock($lock, LOCK_EX | LOCK_NB)) return $this->fallback($cache, 'sync_busy');
                $cache = $this->readCache($token);
            }
            $until = (int) ($cache['rate_limited_until'] ?? 0);
            if ($until > $this->now()) return $this->fallback($cache, $cache['cooldown_reason']??'rate_limited', $until);
            return $this->synchronize($cache, $token);
        } catch (\Throwable) {
            return $this->fallback($cache ?? null, 'sync_unavailable');
        } finally {
            if (is_resource($lock)) fclose($lock);
        }
    }

    private function synchronize(?array $cache, #[\SensitiveParameter] string $token): array
    {
        $headers = ['Accept'=>'application/vnd.github+json','User-Agent'=>'CajeerEngine-Release-Monitor','X-GitHub-Api-Version'=>'2022-11-28'];
        if ($token !== '') $headers['Authorization'] = 'Bearer '.$token;
        $pages = []; $changed = false; $deadline = hrtime(true) + 30_000_000_000;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $previous = $cache['pages'][$page-1] ?? null;
            $request = $headers;
            if (self::validEtag($previous['etag'] ?? null)) $request['If-None-Match'] = $previous['etag'];
            try {
                [$status,$body,$response] = $this->request($page, $request, $deadline);
            } catch (\Throwable) { return $this->transientFailure($cache, $token, 'network_error'); }
            if ($status === 429 || ($status === 403 && (($response['x-ratelimit-remaining'] ?? '') === '0' || isset($response['retry-after'])))) {
                return $this->cooldown($cache, $token, $this->rateLimitUntil($response));
            }
            if ($status === 403) {
                // GitHub secondary limits sometimes have no quota headers. Do not hammer a forbidden endpoint.
                return $this->cooldown($cache, $token, $this->now()+60, 'forbidden');
            }
            if (in_array($status,[408,500,502,503,504],true) && isset($response['retry-after'])) return $this->cooldown($cache, $token, $this->rateLimitUntil($response), 'http_error');
            if ($status === 304 && $previous !== null && isset($request['If-None-Match'])) {
                $entry = $previous;
                // A 304 may omit Link; retain the page's validated pagination metadata.
                if (isset($response['link'])) {
                    try { $entry['next'] = self::nextPage($response['link'], $page); }
                    catch (\Throwable) { return $this->fallback($cache,'invalid_pagination'); }
                }
            } elseif ($status === 200) {
                try {
                    $decoded = json_decode($body,true,512,JSON_THROW_ON_ERROR);
                    if (!str_starts_with(ltrim($body),'[') || !is_array($decoded) || count($decoded)>100) throw new \RuntimeException('Invalid list');
                    $catalog = GitHubReleaseCatalog::normalize($decoded);
                    if ($catalog === null) throw new \RuntimeException('Invalid list');
                    $entry = ['releases'=>$catalog['releases'], 'etag'=>self::validEtag($response['etag']??null)?$response['etag']:null,
                        'next'=>self::nextPage($response['link']??'', $page)];
                    $changed = true;
                } catch (\Throwable) { return $this->fallback($cache,'invalid_response'); }
            } else {
                if (in_array($status,[408,500,502,503,504],true)) return $this->transientFailure($cache,$token,'http_error');
                return $this->fallback($cache, $status===401?'authentication_failed':'http_error');
            }
            $pages[] = $entry;
            if (!$entry['next']) break;
            if ($page === self::MAX_PAGES) return $this->fallback($cache,'pagination_limit');
        }
        $changed = $changed || $pages !== ($cache['pages']??null);
        $releases = self::merge($pages);
        if ($releases === null) return $this->fallback($cache,'duplicate_version');
        $now = gmdate(DATE_ATOM,$this->now());
        $updated = ['endpoint'=>GitHubEndpoints::RELEASES_URL,'scope'=>self::scope($token),'pages'=>$pages,
            'releases'=>$releases,'fetched_at'=>$changed?$now:($cache['fetched_at']??$now),'checked_at'=>$now,'rate_limited_until'=>0];
        $this->writeCache($updated);
        return $this->fromCache($updated,$changed?'fresh':'not_modified');
    }

    /** Never follow Link URLs; only accept a sequential page number and rebuild the canonical URL. */
    private static function nextPage(string $link, int $page): bool
    {
        if ($link === '') return false;
        if (strlen($link)>8192) throw new \RuntimeException('Oversized pagination');
        $next = false;
        foreach (explode(',', $link) as $part) {
            if (!preg_match('/;\s*rel\s*=\s*"?next"?(?:\s*;|\s*$)/i',$part)) continue;
            if ($next || !preg_match('/^\s*<([^>]+)>/',$part,$m)) throw new \RuntimeException('Invalid next link');
            $url = parse_url($m[1]);
            if (!is_array($url) || ($url['scheme']??'')!=='https' || ($url['host']??'')!=='api.github.com' || (isset($url['user']) || isset($url['pass'])) || isset($url['fragment']) || isset($url['port'])) throw new \RuntimeException('Invalid next host');
            if (!preg_match('~^/(?:repos/'.preg_quote(GitHubEndpoints::REPOSITORY,'~').'|repositories/[0-9]+)/releases$~iD',$url['path']??'')) throw new \RuntimeException('Invalid next path');
            parse_str($url['query']??'', $q);
            if (($q['page']??null)!==(string)($page+1) || (isset($q['per_page']) && $q['per_page']!=='100')) throw new \RuntimeException('Invalid next page');
            $next = true;
        }
        return $next;
    }

    private function request(int $page, #[\SensitiveParameter] array $headers, int $deadline): array
    {
        $url = GitHubEndpoints::RELEASES_URL.($page===1?'':'&page='.$page);
        for ($attempt=0; $attempt<3; ++$attempt) {
            $remaining = (int)floor(($deadline-hrtime(true))/1e9);
            if ($remaining<1) throw new \RuntimeException('Synchronization deadline');
            try {
                [$status,$body,$response] = $this->transport !== null ? ($this->transport)($url,$headers)
                    : (new PublicHttpClient(max(1,min($remaining,$this->timeoutSeconds)), $this->rootPath))->requestWithHeaders($url,'GET',$headers,'',4*1024*1024);
                $response = array_change_key_case($response, CASE_LOWER);
                if (!in_array($status,[408,500,502,503,504],true) || isset($response['retry-after']) || $attempt===2) return [$status,$body,$response];
            } catch (\Throwable $e) { if ($attempt===2) throw $e; }
            $delay = 200 * (2 ** $attempt) + random_int(0,100);
            if ($this->sleep !== null) ($this->sleep)($delay); else usleep($delay*1000);
        }
        throw new \RuntimeException('Retry exhausted');
    }

    private function transientFailure(?array $cache, #[\SensitiveParameter] string $token, string $reason): array
    {
        $cache ??= ['endpoint'=>GitHubEndpoints::RELEASES_URL,'scope'=>self::scope($token)];
        $failures = min(6, max(0,(int)($cache['consecutive_failures']??0))+1);
        $cache['consecutive_failures']=$failures;
        return $this->cooldown($cache,$token,$this->now()+min(900,30*(2**($failures-1))),$reason);
    }

    private function cooldown(?array $cache, #[\SensitiveParameter] string $token, int $until, string $reason='rate_limited'): array
    {
        $state = $cache ?? ['endpoint'=>GitHubEndpoints::RELEASES_URL,'scope'=>self::scope($token)];
        $state['rate_limited_until']=$until;
        $state['cooldown_reason']=$reason;
        $this->writeCache($state);
        return $this->fallback($state,$reason,$until);
    }

    public function snapshot(): ?array
    {
        try { $cache=$this->readCache($this->token()); }
        catch (\Throwable) { return $this->fallback(null,'sync_unavailable'); }
        if ($cache===null) return null;
        $until=(int)($cache['rate_limited_until']??0);
        if ($until>$this->now()) return $this->fallback($cache,$cache['cooldown_reason']??'rate_limited',$until);
        return isset($cache['releases'])?$this->fromCache($cache,'cached'):$this->fallback($cache,'not_synchronized');
    }

    private function fallback(?array $cache,string $reason,?int $retryAt=null): array
    {
        $has = isset($cache['releases']);
        return ['releases'=>$cache['releases']??[],'_source'=>$has?GitHubEndpoints::RELEASES_URL:'empty', '_status'=>$has?'stale':'unavailable',
            '_failure'=>$reason,'_retry_at'=>$retryAt===null?null:gmdate(DATE_ATOM,$retryAt), '_fetched_at'=>$cache['fetched_at']??null,
            '_checked_at'=>$cache['checked_at']??null,'_page_count'=>count($cache['pages']??[])];
    }

    private function fromCache(array $cache,string $status): array
    {
        return ['releases'=>$cache['releases'],'_source'=>GitHubEndpoints::RELEASES_URL,'_status'=>$status,
            '_fetched_at'=>$cache['fetched_at']??null,'_checked_at'=>$cache['checked_at']??null,'_page_count'=>count($cache['pages']??[])];
    }

    private function token(): string
    {
        $token=SecretEnvironment::get('UPDATE_GITHUB_TOKEN');
        if ($token===false || $token==='') return '';
        if (!preg_match('/^[A-Za-z0-9_\-]{16,512}$/D',$token)) throw new \RuntimeException('Invalid GitHub credential');
        return $token;
    }

    private static function scope(#[\SensitiveParameter] string $token): string { return $token===''?'public':hash('sha256',$token); }

    private static function merge(array $pages): ?array
    {
        $releases=[];
        foreach ($pages as $page) foreach ($page['releases'] as $release) {
            if (isset($releases[$release['version']])) return null;
            $releases[$release['version']]=$release;
        }
        return array_values($releases);
    }

    private function readCache(#[\SensitiveParameter] string $token): ?array
    {
        try {
            $path=$this->cachePath();
            if ($path===null || !is_file($path) || filesize($path)>self::MAX_BYTES) return null;
            $cache=(new JsonFile($path))->readObject();
            if (($cache['endpoint']??null)!==GitHubEndpoints::RELEASES_URL || ($cache['scope']??'public')!==self::scope($token)) return null;
            if (!isset($cache['releases'])) return ['endpoint'=>GitHubEndpoints::RELEASES_URL,'scope'=>self::scope($token),'consecutive_failures'=>min(6,max(0,(int)($cache['consecutive_failures']??0))),'rate_limited_until'=>(int)($cache['rate_limited_until']??0),'cooldown_reason'=>in_array($cache['cooldown_reason']??null,['forbidden','http_error','network_error'],true)?$cache['cooldown_reason']:'rate_limited'];
            if (!is_array($cache['releases']) || !array_is_list($cache['releases']) || count($cache['releases'])>100*self::MAX_PAGES || !is_string($cache['fetched_at']??null) || strtotime($cache['fetched_at'])===false) return null;
            foreach ($cache['releases'] as $release) if (!self::validRelease($release)) return null;
            if (isset($cache['pages'])) {
                if (!is_array($cache['pages']) || !array_is_list($cache['pages']) || count($cache['pages'])<1 || count($cache['pages'])>self::MAX_PAGES) return null;
                foreach ($cache['pages'] as $i=>$page) {
                    if (!is_array($page) || !is_array($page['releases']??null) || !array_is_list($page['releases']) || count($page['releases'])>100 || ($page['next']??null)!==($i<count($cache['pages'])-1)) return null;
                    foreach ($page['releases'] as $r) if (!is_array($r) || !is_string($r['version']??null)) return null;
                }
                if (self::merge($cache['pages'])!==$cache['releases']) return null;
            }
            return $cache;
        } catch (\Throwable) { return null; }
    }

    private static function validRelease(mixed $release): bool
    {
        if (!is_array($release) || !is_string($release['version']??null)) return false;
        $version=$release['version'];
        if (($release['channel']??null)!==UpdatePreflight::channelOf($version)) return false;
        $tag=null;
        foreach ([$version,'v'.$version] as $candidate) if (($release['source']??null)===GitHubEndpoints::releaseUrl($candidate)) $tag=$candidate;
        if ($tag===null || !is_array($release['artifacts']['dist']??null) || !is_array($release['artifacts'])) return false;
        foreach ($release['artifacts'] as $mode=>$asset) {
            if (!in_array($mode,['source','dist'],true) || !is_array($asset)) return false;
            $name="cajeerengine-$version-$mode.zip";
            foreach (['name'=>$name,'download_url'=>GitHubEndpoints::assetUrl($tag,$name),'checksum_name'=>$name.'.sha256','checksum_url'=>GitHubEndpoints::assetUrl($tag,$name.'.sha256'),'signature_name'=>$name.'.sig','signature_url'=>GitHubEndpoints::assetUrl($tag,$name.'.sig')] as $k=>$v) if (($asset[$k]??null)!==$v) return false;
            if (!is_int($asset['size']??null) || $asset['size']<=0 || (isset($asset['sha256']) && (!is_string($asset['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$asset['sha256'])))) return false;
        }
        return true;
    }

    private function writeCache(array $cache): void
    {
        $path=$this->cachePath();
        if ($path===null) return;
        $json=json_encode($cache,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json)>self::MAX_BYTES) throw new \RuntimeException('Catalog exceeds cache limit');
        OperationFiles::write($path,$json."\n",0660);
    }

    private function cachePath(): ?string { return $this->rootPath===null?null:OperationFiles::path($this->rootPath,'storage/app/updates/github-release-cache.json'); }
    private static function validEtag(mixed $etag): bool { return is_string($etag)&&strlen($etag)<=160&&(bool)preg_match('/^(?:W\/)?"[!#-~]{1,140}"$/D',$etag); }
    private function rateLimitUntil(array $headers): int
    {
        $now=$this->now(); $until=$now+60;
        $after=$headers['retry-after']??null;
        if (is_string($after)) { $value=ctype_digit($after)?$now+min((int)$after,604800):strtotime($after); if ($value!==false) $until=max($until,$value); }
        $reset=$headers['x-ratelimit-reset']??null;
        if (($headers['x-ratelimit-remaining']??null)==='0' && is_string($reset)&&ctype_digit($reset)) $until=max($until,(int)$reset);
        return min($until,$now+604800);
    }
}
