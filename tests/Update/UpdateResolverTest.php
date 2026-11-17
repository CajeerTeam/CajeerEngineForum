<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Update;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateResolver;
use PHPUnit\Framework\TestCase;

final class UpdateResolverTest extends TestCase
{
    public function testBundledMetadataIsAvailable(): void
    {
        $root = dirname(__DIR__, 2);
        $config = new ConfigRepository($root);
        $updates = $config->get('updates', []);
        $updates['core']['releases_url'] = $root.'/tests/nonexistent-release-catalog.json';
        $diag = (new UpdateResolver($updates, $root))->diagnostics('1.0.4');
        self::assertTrue($diag['metadata_ready']);
        // The bundled catalog can legitimately be empty before first release.
        // Verify the actual catalog, not a fictitious minimum release count.
        $bundled=$root.'/resources/updates/releases.json';
        self::assertSame($bundled, $diag['bundled_metadata_file']);
        self::assertFileExists($bundled);
        $metadata=json_decode((string)file_get_contents($bundled), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata['releases'] ?? null);
        self::assertSame(count($metadata['releases']), $diag['release_count']);
    }
}
