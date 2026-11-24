<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Automatic read-only discovery run by the already deployed CMS scheduler. */
final readonly class AutomaticReleaseDiscovery
{
    public function __construct(private ConfigRepository $config, private ?GitHubReleaseMonitor $monitor = null)
    {
    }

    public function enabled(): bool
    {
        return (new UpdatePolicy($this->config))->discoveryEnabled();
    }

    public function intervalSeconds(): int
    {
        return max(3600, min(604800, $this->config->int('updates.core.auto_check_interval_seconds', 21600)));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        if (!$this->enabled()) return ['ok' => true, 'skipped' => 'disabled'];
        $result = (new UpdateResolver($this->config->get('updates', []), $this->config->rootPath(), $this->monitor))
            ->check((new \CajeerEngine\Forum\ForumVersion($this->config->rootPath()))->current());
        // An offline bundled catalog is useful for diagnostics, but it is NOT a
        // successful GitHub poll; retry on the next slot and expose degraded state.
        return [
            'ok' => $result['github_reachable'] === true,
            'current_version' => $result['current_version'],
            'channel' => $result['channel'],
            'update_available' => $result['update_available'],
            'latest_version' => $result['latest']['version'] ?? null,
            'next_version' => $result['next']['version'] ?? null,
            'upgrade_path' => $result['upgrade_path'],
            'metadata_status' => $result['metadata_status'],
            'metadata_fetched_at' => $result['metadata_fetched_at'],
            'metadata_checked_at' => $result['metadata_checked_at'],
            'metadata_page_count' => $result['metadata_page_count'],
            'metadata_failure' => $result['metadata_failure'],
            'retry_at' => $result['retry_at'],
        ] + ($result['github_reachable'] ? [] : ['error' => 'GitHub release metadata unavailable']);
    }
}
