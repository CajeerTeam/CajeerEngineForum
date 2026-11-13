<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

/** Forum releases evolve independently of the inherited engine/API/assets. */
final readonly class ForumVersion
{
    public function __construct(private string $root) {}

    public function upstream(): string
    {
        return self::validate(trim((string) file_get_contents($this->root.'/VERSION')));
    }

    public function current(): string
    {
        $release = json_decode((string) file_get_contents($this->root.'/release.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($release) || ($release['edition'] ?? null) !== 'forum' || ($release['upstream_version'] ?? null) !== $this->upstream() || ($release['version'] ?? null) !== $this->upstream()) {
            throw new \RuntimeException('Invalid Forum Edition release identity');
        }
        return self::validate($release['edition_version'] ?? null);
    }

    public static function validate(mixed $version): string
    {
        if (!is_string($version) || !preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version)) {
            throw new \RuntimeException('Invalid release version');
        }
        return $version;
    }
}
