<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use Plan2net\PlaywrightToolkit\Database\SeedSources;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeedSourcesFixturesTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir() . '/playwright-seed-sources-' . uniqid('', true);
        mkdir($this->workingDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workingDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workingDirectory);
    }

    #[Test]
    public function loadsFixturesAsNameToContentsInManifestOrder(): void
    {
        file_put_contents($this->workingDirectory . '/pages.sql', 'PAGES');
        file_put_contents($this->workingDirectory . '/users.sql', 'USERS');

        self::assertSame(
            ['users.sql' => 'USERS', 'pages.sql' => 'PAGES'],
            SeedSources::loadFixtures($this->workingDirectory, ['users.sql', 'pages.sql'])
        );
    }

    #[Test]
    public function throwsWhenAFixtureInTheManifestIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        SeedSources::loadFixtures($this->workingDirectory, ['does-not-exist.sql']);
    }
}
