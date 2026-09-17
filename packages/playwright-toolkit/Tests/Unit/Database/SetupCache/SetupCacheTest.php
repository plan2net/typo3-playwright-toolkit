<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\SetupCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\SetupCache\DeltaHeader;
use Plan2net\PlaywrightToolkit\Database\SetupCache\SetupCache;

final class SetupCacheTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/setup-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[Test]
    public function readsBackTheDeltaItWrote(): void
    {
        $cache = new SetupCache($this->directory);
        $header = self::header();

        $cache->write(self::KEY, $header, 'DELETE FROM "pages";');

        $delta = $cache->read(self::KEY);

        self::assertNotNull($delta);
        self::assertEquals($header, $delta['header']);
        self::assertSame('DELETE FROM "pages";', $delta['sql']);
    }

    #[Test]
    #[DataProvider('keysThatAreNotAKey')]
    public function refusesReadingWith(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SetupCache($this->directory))->read($key);
    }

    #[Test]
    public function refusesAWriteBeforeItCreatesAnything(): void
    {
        $cache = new SetupCache($this->directory);

        try {
            $cache->write('../escaped', self::header(), 'DELETE FROM "pages";');
            self::fail('Expected the key to be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysThatAreNotAKey(): iterable
    {
        yield 'a traversal' => ['../../../etc/passwd'];
        yield 'a separator' => ['0123456789abcdef/0123456789abcde'];
        yield 'too short' => ['0123456789abcdef'];
        yield 'upper case hex' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'empty' => [''];
    }

    private static function header(): DeltaHeader
    {
        return new DeltaHeader(
            engine: Engine::Postgres,
            templateFingerprint: 'fingerprint',
            tables: ['pages' => 'hash-a'],
            state: ['slug' => '/text-ABCD1234EFGH5678'],
        );
    }
}
