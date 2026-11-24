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
        $fixture = sys_get_temp_dir().'/ce-update-catalog-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixture.'/resources/updates', 0700, true));
        $path = $fixture.'/resources/updates/releases.json';
        try {
            self::assertTrue(copy($root.'/resources/updates/releases.json', $path));
            $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($catalog['releases']);
            $diag = (new UpdateResolver($config->get('updates', []), $fixture))->diagnostics('1.0.4');
            self::assertTrue($diag['metadata_ready']);
            // An empty catalogue is valid before the first authenticated publication.
            self::assertSame($path, $diag['metadata_source']);
            self::assertSame(count($catalog['releases']), $diag['release_count']);
        } finally {
            @unlink($path);
            @rmdir($fixture.'/resources/updates');
            @rmdir($fixture.'/resources');
            @rmdir($fixture);
        }
    }
}
