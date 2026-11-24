<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Download a fresh, advertised GitHub production dist and authenticate it before publication. */
final readonly class GitHubReleaseDownloader
{
    /** @param null|\Closure(string,resource,int,int):mixed $transport */
    public function __construct(
        private string $rootPath,
        private ConfigRepository $config,
        private ?GitHubReleaseMonitor $monitor = null,
        private ?\Closure $transport = null,
        private ?\Closure $resumeTransport = null,
    ) {}

    /** @return array<string,mixed> */
    public function download(?string $requestedVersion = null): array
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Release downloads require operator CLI');
        if ($this->config->string('updates.core.releases_url', GitHubEndpoints::RELEASES_URL) !== GitHubEndpoints::RELEASES_URL) {
            throw new \RuntimeException('Remote downloads require the canonical GitHub release catalog');
        }
        $lock = OperationLock::acquire($this->rootPath, false);
        // Use a distinct lock from operations.lock: downloads cannot starve site requests.
        $downloadGuard = null;
        $version = null; $declaredSize = null; $received = 0; $lastProgress = 0;
        try {
            $directory = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads');
            OperationFiles::dir($directory, 0700);
            $guardPath = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads/.download.lock');
            $downloadGuard = OperationLock::openLockFile($guardPath, 0600);
            if (!is_resource($downloadGuard) || !flock($downloadGuard, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Another release download is in progress');
            }
            if (OperationLock::active($this->rootPath) || is_file($this->rootPath . '/storage/app/backup-restore.json') || is_file($this->rootPath . '/storage/app/extensions/deployment.json')) {
                throw new \RuntimeException('Recovery in progress; do not download updates');
            }
            PartialRelease::cleanup($directory);
            $resolver = new UpdateResolver($this->config->get('updates', []), $this->rootPath, $this->monitor);
            $current = (new \CajeerEngine\Forum\ForumVersion($this->rootPath))->current();
            $check = $resolver->check($current);
            if (!in_array($check['metadata_status'] ?? '', ['fresh', 'not_modified'], true)
                || ($check['metadata_source'] ?? null) !== GitHubEndpoints::RELEASES_URL) {
                throw new \RuntimeException('Fresh GitHub Release metadata required; cached/offline metadata is not install authorization');
            }
            $release = $check['next'] ?? null;
            if (!is_array($release) || !($check['update_available'] ?? false)) {
                throw new \RuntimeException('No newer release available on selected channel');
            }
            $version = $release['version'] ?? null;
            if (!is_string($version) || ($requestedVersion !== null && $requestedVersion !== $version)) {
                throw new \RuntimeException('Requested release is not the next eligible GitHub release');
            }
            $asset = $release['artifacts']['dist'] ?? null;
            $name = 'cajeerengine-' . $version . '-dist.zip';
            if (!is_array($asset) || ($asset['name'] ?? null) !== $name
                || ($asset['checksum_name'] ?? null) !== $name . '.sha256'
                || ($asset['signature_name'] ?? null) !== $name . '.sig') {
                throw new \RuntimeException('GitHub release lacks the signed production distribution');
            }
            $tag = 'v' . $version;
            // Some releases use unprefixed SemVer tags. Use only a fixed canonical tag derived
            // from the release source after validating its precise repository URL.
            $source = $release['source'] ?? null;
            if ($source === GitHubEndpoints::releaseUrl($version)) $tag = $version;
            elseif ($source !== GitHubEndpoints::releaseUrl('v' . $version)) {
                throw new \RuntimeException('Unexpected GitHub release identity');
            }
            $urls = [
                'package' => GitHubEndpoints::assetUrl($tag, $name),
                'checksum' => GitHubEndpoints::assetUrl($tag, $name . '.sha256'),
                'signature' => GitHubEndpoints::assetUrl($tag, $name . '.sig'),
            ];
            foreach (['package' => 'download_url', 'checksum' => 'checksum_url', 'signature' => 'signature_url'] as $type => $key) {
                if (($asset[$key] ?? null) !== $urls[$type]) throw new \RuntimeException('Noncanonical GitHub asset location');
            }
            $maxBytes = max(1048576, min(2147483648, $this->config->int('updates.core.download_max_bytes', 2147483648)));
            $declaredSize = $asset['size'] ?? null;
            if (!is_int($declaredSize) || $declaredSize < 1024 || $declaredSize > $maxBytes) {
                throw new \RuntimeException('Invalid or oversized GitHub dist asset');
            }
            $available = disk_free_space($directory);
            if ($available !== false && $available < ($declaredSize * 2 + 33554432)) {
                throw new \RuntimeException('Insufficient disk space for release staging');
            }
            $path = OperationFiles::path($this->rootPath, 'storage/app/updates/downloads/' . $version);
            OperationFiles::dir($path, 0700);
            if (is_link($path)) throw new \RuntimeException('Untrusted release directory');
            $published = $path . '/' . $name;
            $receipt = $path . '/verified.json';
            if (is_file($receipt) && !is_link($receipt) && is_file($published) && !is_link($published)) {
                try {
                    $prior = OperationFiles::read($receipt);
                    $digest = $prior['sha256'] ?? '';
                    if (($prior['version'] ?? null) === $version && is_string($digest)
                        && preg_match('/^[a-f0-9]{64}$/D', $digest)
                        && isset($asset['sha256']) && hash_equals($asset['sha256'], $digest) && filesize($published) === $declaredSize) {
                        $verified = (new UpdateManager($this->rootPath, $this->config))->verifyPackage($published, $digest);
                        if (($verified['ok'] ?? false) && ($verified['version'] ?? '') === $version && ($verified['mode'] ?? '') === 'dist'
                            && $this->checksumMatches($published . '.sha256', $name, $digest)) {
                            UpdatePreflight::identity($verified['manifest'],$current,$resolver->channel(),$version);
                            $this->progress($version, 'verified', $declaredSize, $declaredSize);
                            return $this->result($version, $published, $verified, true);
                        }
                    }
                } catch (\Throwable) { /* Replace unverified cache only after fetching new verified bytes. */ }
            }
            // An old receipt must never mark partially replaced or invalid bytes as ready.
            if (file_exists($receipt) || is_link($receipt)) OperationFiles::remove($receipt);
            $suffix = bin2hex(random_bytes(12));
            $parts = [
                'package' => $path . '/.' . $suffix . '.zip',
                'checksum' => $path . '/.' . $suffix . '.sha256',
                'signature' => $path . '/.' . $suffix . '.sig',
            ];
            $partial = $path . '/.partial.zip';
            $partialState = $path . '/.partial.json';
            $resumable = $this->transport === null || $this->resumeTransport !== null;
            if ($resumable) $parts['package'] = $partial;
            $temporarySignature = $parts['package'] . '.sig';
            $preservePartial = $resumable;
            try {
                $timeout = max(15, min(1800, $this->config->int('updates.core.download_timeout_seconds', 300)));
                $sizes = [
                    'checksum' => 256,
                    'signature' => 8192,
                    'package' => $declaredSize,
                ];
                foreach (['checksum', 'signature', 'package'] as $type) {
                    if ($type === 'package' && $resumable) {
                        $text = file_get_contents($parts['checksum']);
                        if (!is_string($text) || !preg_match('/^([a-f0-9]{64})  ' . preg_quote($name, '/') . '\r?\n?$/D', $text, $match)) throw new \RuntimeException('Invalid GitHub checksum sidecar');
                        if (isset($asset['sha256']) && !hash_equals($asset['sha256'], $match[1])) throw new \RuntimeException('GitHub release SHA-256 checksum mismatch');
                        (new UpdateManager($this->rootPath,$this->config))->verifyReleaseDigest($match[1],$parts['signature']);
                        // GitHub may omit the asset digest. Authenticate freshly fetched sidecars before reusing bytes.
                        if (is_file($published) && !is_link($published) && filesize($published)===$declaredSize) {
                            $existing=(new UpdateManager($this->rootPath,$this->config))->verifyPackage($published,$match[1],$parts['signature']);
                            if (($existing['ok']??false) && $existing['version']===$version && $existing['mode']==='dist') {
                                UpdatePreflight::identity($existing['manifest'],$current,$resolver->channel(),$version);
                                OperationFiles::copy($parts['checksum'],$published.'.sha256');
                                OperationFiles::copy($parts['signature'],$published.'.sig');
                                $this->receipt($receipt,$version,$check['channel'],$existing,$declaredSize,$tag);
                                $preservePartial=false;
                                $this->progress($version,'verified',$declaredSize,$declaredSize);
                                return $this->result($version,$published,$existing,true);
                            }
                        }
                        $identity = ['version'=>$version, 'size'=>$declaredSize, 'sha256'=>$match[1], 'signature_sha256'=>OperationFiles::hash($parts['signature']), 'tag'=>$tag];
                        $stream = PartialRelease::open($partial, $partialState, $identity);
                        $preservePartial = true;
                        $received = (int) fstat($stream)['size'];
                        $this->progress($version, 'downloading', $received, $declaredSize);
                    } else $stream = fopen($parts[$type], 'xb');
                    if (!is_resource($stream)) throw new \RuntimeException('Cannot create download staging file');
                    try {
                        chmod($parts[$type], 0600);
                        if ($type === 'package' && $resumable) {
                            $progress = function(int $bytes) use ($version, $declaredSize, &$received, &$lastProgress): void {
                                $received = $bytes;
                                if (time() - $lastProgress >= 2) { $lastProgress = time(); $this->progress($version, 'downloading', $bytes, $declaredSize); }
                            };
                            $size = $this->resumeTransport !== null
                                ? ($this->resumeTransport)($urls[$type], $stream, $declaredSize, $timeout, $progress)
                                : (new GitHubAssetTransport())->download($urls[$type], $stream, $declaredSize, $timeout, true, $progress);
                        } else $size = $this->transport !== null
                            ? ($this->transport)($urls[$type], $stream, $sizes[$type], $timeout)
                            : (new GitHubAssetTransport())->download($urls[$type], $stream, $sizes[$type], $timeout);
                        if (!is_int($size) || $size < 1 || $size > $sizes[$type]
                            || fstat($stream)['size'] !== $size || !fflush($stream) || !fsync($stream)) {
                            throw new \RuntimeException('Incomplete or oversized GitHub release asset');
                        }
                    } finally { if ($type === 'package') { $received = (int) fstat($stream)['size']; fflush($stream); fsync($stream); } fclose($stream); }
                }
                clearstatcache(true, $parts['package']);
                if (filesize($parts['package']) !== $declaredSize) {
                    throw new \RuntimeException('Downloaded ZIP length differs from GitHub release metadata');
                }
                $preservePartial = false; // Complete bytes that fail authentication must never be reused.
                $this->progress($version, 'verifying', $declaredSize, $declaredSize);
                $sha = OperationFiles::hash($parts['package']);
                if (!$this->checksumMatches($parts['checksum'], $name, $sha)) {
                    throw new \RuntimeException('GitHub release SHA-256 checksum mismatch');
                }
                if (isset($asset['sha256']) && (!is_string($asset['sha256']) || !hash_equals($asset['sha256'], $sha))) {
                    throw new \RuntimeException('GitHub asset digest mismatch');
                }
                OperationFiles::copy($parts['signature'], $temporarySignature);
                $verified = (new UpdateManager($this->rootPath, $this->config))->verifyPackage($parts['package'], $sha);
                if (!($verified['ok'] ?? false) || ($verified['version'] ?? '') !== $version || ($verified['mode'] ?? '') !== 'dist') {
                    throw new \RuntimeException('Signed ZIP release verification failed: ' . ($verified['error'] ?? 'wrong version or mode'));
                }
                UpdatePreflight::identity($verified['manifest'],$current,$resolver->channel(),$version);
                // Write sidecars first. Receipt is the final publication commit marker.
                OperationFiles::copy($parts['checksum'], $published . '.sha256');
                OperationFiles::copy($parts['signature'], $published . '.sig');
                OperationFiles::copy($parts['package'], $published);
                $this->receipt($receipt,$version,$check['channel'],$verified,$declaredSize,$tag);
                $this->progress($version, 'verified', $declaredSize, $declaredSize);
                return $this->result($version, $published, $verified, false);
            } finally {
                foreach (array_merge(array_values($parts), [$temporarySignature]) as $temp) {
                    if ($temp === $partial && $preservePartial) continue;
                    if (is_file($temp) && !is_link($temp)) unlink($temp);
                }
                if ($resumable && !$preservePartial && is_file($partialState)) OperationFiles::remove($partialState);
            }
        } catch (\Throwable $e) {
            if ($version !== null && is_int($declaredSize)) $this->progress($version, 'failed', $received, $declaredSize);
            throw $e;
        } finally {
            if (is_resource($downloadGuard)) { flock($downloadGuard, LOCK_UN); fclose($downloadGuard); }
            OperationLock::release($lock);
        }
    }

    private function receipt(string $path,string $version,string $channel,array $verified,int $size,string $tag): void
    {
        OperationFiles::json($path,[
            'format'=>'cajeerengine.verified-release.v1','version'=>$version,'channel'=>$channel,
            'sha256'=>$verified['sha256'],'key_id'=>$verified['key_id'],'size'=>$size,
            'downloaded_at'=>gmdate(DATE_ATOM),'source'=>GitHubEndpoints::releaseUrl($tag),
        ],0600);
    }

    private function progress(string $version, string $state, int $received, int $size): void
    {
        try {
            OperationFiles::json(OperationFiles::path($this->rootPath, 'storage/app/updates/download-status.json'), [
                'version'=>$version, 'state'=>$state, 'received_bytes'=>max(0,min($received,$size)), 'total_bytes'=>$size, 'checked_at'=>gmdate(DATE_ATOM),
            ], 0644);
        } catch (\Throwable) { /* diagnostic failure cannot invalidate verified bytes */ }
    }

    private function checksumMatches(string $path, string $name, string $digest): bool
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 256) return false;
        $text = file_get_contents($path);
        return is_string($text) && (bool) preg_match('/^([a-f0-9]{64})  ' . preg_quote($name, '/') . '\r?\n?$/D', $text, $match)
            && hash_equals($match[1], $digest);
    }

    /** @param array<string,mixed> $verified @return array<string,mixed> */
    private function result(string $version, string $path, array $verified, bool $cached): array
    {
        return [
            'ok' => true,
            'version' => $version,
            'state' => 'ready_to_install',
            'cached' => $cached,
            'package' => $path,
            'sha256' => $verified['sha256'],
            'key_id' => $verified['key_id'],
            'file_count' => $verified['file_count'],
            'next_command' => 'php bin/cajeer update:apply --package=' . $path . ' --sha256=' . $verified['sha256'],
        ];
    }
}
