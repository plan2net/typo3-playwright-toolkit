<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\Fixtures;

final class FixturesTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/playwright-fixtures-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixturesPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->fixturesPath)) {
            rmdir($this->fixturesPath);
        }
    }

    #[Test]
    public function failsWhenTheFixturesDirectoryIsMissing(): void
    {
        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['main' => 1]))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString($this->fixturesPath, $result->detail);
        self::assertSame(['010-root-page.sql'], $result->missingFiles);
    }

    #[Test]
    public function failsWhenAManifestEntryIsMissing(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents($this->fixturesPath . '/010-root-page.sql', "INSERT INTO pages (uid) VALUES (1);\n");

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql', '020-content.sql'], ['main' => 1]))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('020-content.sql', $result->detail);
        self::assertSame(['020-content.sql'], $result->missingFiles);
    }

    #[Test]
    public function failsWhenTheRootPageUidIsNotTheSitesRootPage(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents(
            $this->fixturesPath . '/010-root-page.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (1, 0, 'Home');\n"
        );

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['main' => 42]))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('42', $result->detail);
    }

    #[Test]
    public function asksForNoFileWhenTheFixturesPathIsNotConfigured(): void
    {
        $result = (new Fixtures('', [], ['main' => 1]))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('fixturesPath', $result->detail);
        self::assertSame([], $result->missingFiles);
    }

    #[Test]
    public function failsWhenNoFixtureIsConfiguredAtAll(): void
    {
        mkdir($this->fixturesPath, 0777, true);

        $result = (new Fixtures($this->fixturesPath, [], ['main' => 1]))->run();

        self::assertFalse($result->passed);
        self::assertSame(['010-root-page.sql'], $result->missingFiles);
    }

    #[Test]
    public function listsTheSitesWhenTheTestingUrlPicksNoneForTheRootPageFixture(): void
    {
        mkdir($this->fixturesPath, 0777, true);

        $result = (new Fixtures($this->fixturesPath, [], ['shop' => 2573, 'main' => 1], null))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('shop → 2573, main → 1', $result->detail);
    }

    #[Test]
    public function listsTheSitesWhenTheRootPageFixtureIsMissing(): void
    {
        mkdir($this->fixturesPath, 0777, true);

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['shop' => 2573, 'main' => 1], null))->run();

        self::assertStringContainsString('shop → 2573, main → 1', $result->detail);
    }

    #[Test]
    public function failsWhenTheProjectConfiguresNoSite(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents($this->fixturesPath . '/010-root-page.sql', "INSERT INTO pages (uid) VALUES (1);\n");

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], []))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('no site', $result->detail);
    }

    #[Test]
    public function namesThePageItAgreedOn(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents(
            $this->fixturesPath . '/010-root-page.sql',
            "INSERT INTO pages (uid, pid) VALUES (42, 0);\n"
        );

        $detail = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['main' => 42]))->run()->detail;

        self::assertStringContainsString('page 42', $detail);
    }

    #[Test]
    public function acceptsTheRootOfASiteThatIsNotListedFirst(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents(
            $this->fixturesPath . '/010-root-page.sql',
            "INSERT INTO pages (uid, pid) VALUES (1, 0);\n"
        );

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['shop' => 2573, 'main' => 1]))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('site main', $result->detail);
    }

    #[Test]
    public function saysWhenItCannotReadTheSeededRoot(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents($this->fixturesPath . '/010-root-page.sql', "INSERT INTO pages SELECT * FROM other_pages;\n");

        $result = (new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['main' => 1]))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('could not verify the seeded root', $result->detail);
    }

    #[Test]
    public function passesWhenTheSeededRootPageIsTheSitesRootPage(): void
    {
        mkdir($this->fixturesPath, 0777, true);
        file_put_contents(
            $this->fixturesPath . '/010-root-page.sql',
            "INSERT INTO `pages` (`pid`, `title`, `uid`) VALUES (0, 'Home', 42);\n"
        );

        self::assertTrue((new Fixtures($this->fixturesPath, ['010-root-page.sql'], ['main' => 42]))->run()->passed);
    }
}
