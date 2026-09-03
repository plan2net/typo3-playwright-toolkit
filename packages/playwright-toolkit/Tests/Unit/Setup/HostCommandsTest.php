<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\HostCommands;

final class HostCommandsTest extends TestCase
{
    #[Test]
    public function printsTheHostnameFlagAndTheRestartItNeeds(): void
    {
        self::assertSame(
            [
                'ddev config --additional-hostnames=example-testing',
                'ddev restart',
            ],
            HostCommands::block('--additional-hostnames=example-testing')
        );
    }

    #[Test]
    public function installsTheAddonBeforeAnythingElse(): void
    {
        self::assertSame(
            [
                'ddev add-on get https://github.com/plan2net/typo3-playwright-toolkit/releases/latest/download/ddev-typo3-playwright-toolkit.tar.gz',
                'ddev restart',
            ],
            HostCommands::block(null, needsAddon: true)
        );
    }

    #[Test]
    public function printsNothingWhenThereIsNothingToConfigure(): void
    {
        self::assertSame([], HostCommands::block(null));
    }

    #[Test]
    public function installsTheBrowserAfterTheRestartThatGivesItItsPath(): void
    {
        self::assertSame(
            [
                'ddev config --additional-hostnames=example-testing \\',
                '    --web-environment-add=PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.cache/ms-playwright',
                'ddev restart',
                'ddev npx playwright install --with-deps chromium',
            ],
            HostCommands::block('--additional-hostnames=example-testing', true)
        );
    }

    #[Test]
    public function asksForNothingWhenTheDirectoryIsAlreadyConfigured(): void
    {
        self::assertSame(
            [],
            HostCommands::block(null, testDirectory: 'tests/e2e', testDirectoryConfigured: true)
        );
    }

    #[Test]
    public function tellsDdevAboutANonDefaultTestDirectory(): void
    {
        self::assertSame(
            [
                'ddev config --web-environment-add=PW_TEST_DIR=tests/e2e',
                'ddev restart',
            ],
            HostCommands::block(null, testDirectory: 'tests/e2e')
        );
    }

    #[Test]
    public function installsTheToolkitInTheTestDirectory(): void
    {
        self::assertSame(
            [
                'cd tests/playwright',
                'ddev npm init -y && ddev npm pkg set type=module',
                'ddev npm i -D @plan2net/typo3-playwright-toolkit@1.0.0 @playwright/test',
            ],
            HostCommands::block(null, needsNpm: true, version: '1.0.0')
        );
    }

    #[Test]
    public function pinsNoVersionWhenTheExtensionIsNotARelease(): void
    {
        self::assertContains(
            'ddev npm i -D @plan2net/typo3-playwright-toolkit @playwright/test',
            HostCommands::block(null, needsNpm: true, version: 'dev-main')
        );
    }

    #[Test]
    public function skipsNpmInitWhenThePackageFileIsThere(): void
    {
        self::assertSame(
            [
                'cd tests/playwright',
                'ddev npm i -D @plan2net/typo3-playwright-toolkit@1.0.0 @playwright/test',
            ],
            HostCommands::block(null, needsNpm: true, version: '1.0.0', hasPackageFile: true)
        );
    }

    #[Test]
    public function printsEveryLineInTheDocumentedOrder(): void
    {
        $expected = <<<'SHELL'
            ddev config --additional-hostnames=example,example-testing \
                --web-environment-add=PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.cache/ms-playwright \
                --web-environment-add=PW_TEST_DIR=tests/e2e
            ddev restart
            cd tests/e2e
            ddev npm init -y && ddev npm pkg set type=module
            ddev npm i -D @plan2net/typo3-playwright-toolkit@1.0.0 @playwright/test
            ddev npx playwright install --with-deps chromium
            SHELL;

        $lines = HostCommands::block(
            '--additional-hostnames=example,example-testing',
            needsBrowsers: true,
            testDirectory: 'tests/e2e',
            needsNpm: true,
            version: '1.0.0'
        );

        self::assertSame($expected, implode("\n", $lines));
    }

    #[Test]
    public function quotesAFlagThatCarriesAShellCharacter(): void
    {
        self::assertSame(
            [
                "ddev config '--additional-hostnames=one;rm -rf /'",
                'ddev restart',
            ],
            HostCommands::block('--additional-hostnames=one;rm -rf /')
        );
    }

    #[Test]
    public function quotesADirectoryThatCarriesAShellCharacter(): void
    {
        self::assertContains(
            "cd 'tests; rm -rf /'",
            HostCommands::block(null, testDirectory: 'tests; rm -rf /', needsNpm: true)
        );
    }
}
