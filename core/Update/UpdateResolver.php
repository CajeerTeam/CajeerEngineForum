<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

final readonly class UpdateResolver
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private ?string $rootPath = null, private ?GitHubReleaseMonitor $monitor = null)
    {
    }

    public function channel(): string
    {
        return (string) ($this->config['core']['channel'] ?? 'stable');
    }

    public function releasesUrl(): ?string
    {
        $value = $this->config['core']['releases_url'] ?? GitHubEndpoints::RELEASES_URL;
        return is_string($value) && $value !== '' ? $value : GitHubEndpoints::RELEASES_URL;
    }

    public function registryUrl(): ?string
    {
        $value = $this->config['registry']['url'] ?? GitHubEndpoints::REGISTRY_URL;
        return is_string($value) && $value !== '' ? $value : GitHubEndpoints::REGISTRY_URL;
    }

    /** @return array<string, mixed> */
    public function check(string $currentVersion): array
    {
        $metadata = $this->metadata();
        $releases = is_array($metadata['releases'] ?? null) ? $metadata['releases'] : [];
        $channel = $this->channel();
        $latest = null;
        foreach ($releases as $release) {
            if (!is_array($release)) { continue; }
            $releaseChannel = (string) ($release['channel'] ?? 'stable');
            if ($releaseChannel !== $channel && $channel !== 'dev') { continue; }
            $version = (string) ($release['version'] ?? '');
            try { if (UpdatePreflight::channelOf($version)!==$releaseChannel) continue; } catch (\Throwable) { continue; }
            if (!is_array($release['artifacts']['dist']??null)) continue;
            if ($latest === null || version_compare($version, (string) $latest['version'], '>')) {
                $latest = $release;
            }
        }

        $updateAvailable = $latest !== null && version_compare((string) $latest['version'], $currentVersion, '>');
        $result = [
            'current_version' => $currentVersion,
            'channel' => $channel,
            'metadata_source' => $metadata['_source'] ?? 'none',
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'metadata_status' => $metadata['_status'] ?? 'offline',
            'github_reachable' => in_array($metadata['_status'] ?? '', ['fresh', 'not_modified'], true),
            'metadata_fetched_at' => $metadata['_fetched_at'] ?? null,
            'metadata_checked_at' => $metadata['_checked_at'] ?? null,
            'metadata_page_count' => $metadata['_page_count'] ?? 0,
            'metadata_failure' => $metadata['_failure'] ?? null,
            'retry_at' => $metadata['_retry_at'] ?? null,
            'checked_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'update_available' => $updateAvailable,
            'latest' => $latest,
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
        ];
        if ($result['metadata_failure'] !== 'sync_busy') $this->writeLastCheck($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function diagnostics(string $currentVersion): array
    {
        $last = $this->lastCheck();
        $metadata = $this->metadata(false);
        return [
            'channel' => $this->channel(),
            'current_version' => $currentVersion,
            'releases_url' => $this->releasesUrl(),
            'registry_url' => $this->registryUrl(),
            'signature_required' => (bool) ($this->config['registry']['signature_required'] ?? true),
            'metadata_source' => $metadata['_source'] ?? 'empty',
            'metadata_ready' => ($metadata['_source'] ?? 'empty') !== 'empty',
            'metadata_status' => $metadata['_status'] ?? 'offline',
            'github_reachable' => in_array($metadata['_status'] ?? '', ['fresh', 'not_modified'], true),
            'metadata_fetched_at' => $metadata['_fetched_at'] ?? null,
            'metadata_checked_at' => $metadata['_checked_at'] ?? null,
            'metadata_page_count' => $metadata['_page_count'] ?? 0,
            'metadata_failure' => $metadata['_failure'] ?? null,
            'retry_at' => $metadata['_retry_at'] ?? null,
            'release_count' => is_array($metadata['releases'] ?? null) ? count($metadata['releases']) : 0,
            'last_check' => $last,
            'local_metadata_file' => $this->localMetadataPath(),
            'bundled_metadata_file' => $this->bundledMetadataPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(bool $fetch = true): array
    {
        $url = $this->releasesUrl();
        if ($url === GitHubEndpoints::RELEASES_URL) {
            $monitor = $this->monitor ?? new GitHubReleaseMonitor($this->rootPath, timeoutSeconds: max(1,min(15,(int)($this->config['core']['timeout']??5))));
            $data = $fetch ? $monitor->fetch() : $monitor->snapshot();
            if ($data !== null && ($data['_source'] ?? 'empty') !== 'empty') return $data;
            $failure = $data ?? [];
        } elseif ($url !== null && $fetch) {
            $data = $this->readJson($url);
            if ($data !== null) { $data['_source'] = $url; return $data; }
        }

        foreach ([$this->localMetadataPath(), $this->bundledMetadataPath()] as $path) {
            if ($path !== null && is_file($path)) {
                $data = $this->readJson($path);
                if ($data !== null) { $data['_source'] = $path; return $data + array_diff_key($failure ?? [], ['releases'=>true,'_source'=>true]); }
            }
        }

        return ['releases' => [], '_source' => 'empty'] + ($failure ?? []);
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $source, bool $github = false): ?array
    {
        $contents = null;
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            try {
                $headers = $github
                    ? ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'CajeerEngine-Updater', 'X-GitHub-Api-Version' => '2022-11-28']
                    : ['Accept' => 'application/json'];
                [$status, $body] = (new \CajeerEngine\Security\PublicHttpClient())->request($source, 'GET', $headers, '', 4 * 1024 * 1024);
                if ($status >= 200 && $status < 300) { $contents = $body; }
            } catch (\Throwable) { /* Fall back to bundled metadata; never follow redirects. */ }
        } elseif (is_file($source)) {
            $contents = @file_get_contents($source);
        }
        if (!is_string($contents) || trim($contents) === '') { return null; }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) return null;
        if ($github) return str_starts_with(ltrim($contents), '[') ? GitHubReleaseCatalog::normalize($decoded) : null;
        if (!is_array($decoded['releases'] ?? null)) return null;
        return $decoded;
    }

    private function localMetadataPath(): ?string
    {
        return $this->rootPath === null ? null : $this->rootPath . '/storage/app/updates/releases.json';
    }

    private function bundledMetadataPath(): ?string
    {
        return $this->rootPath === null ? null : $this->rootPath . '/resources/updates/releases.json';
    }

    /** @param array<string, mixed> $result */
    private function writeLastCheck(array $result): void
    {
        if ($this->rootPath === null) { return; }
        $path = $this->rootPath . '/storage/app/updates/last-check.json';
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0775, true); }
        OperationFiles::json($path,$result,0640);
    }

    /** @return array<string, mixed>|null */
    private function lastCheck(): ?array
    {
        if ($this->rootPath === null) { return null; }
        $path = $this->rootPath . '/storage/app/updates/last-check.json';
        if (!is_file($path)) { return null; }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }
}
