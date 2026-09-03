<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\BrowsersPath;

final class BrowsersPathTest extends TestCase
{
    #[Test]
    public function failsWhenThePathIsNotSet(): void
    {
        $result = (new BrowsersPath(null))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('PLAYWRIGHT_BROWSERS_PATH', $result->detail);
    }

    #[Test]
    public function failsWhenThePathIsOutsideTheProject(): void
    {
        $result = (new BrowsersPath('/root/.cache/ms-playwright'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('/var/www/html', $result->detail);
    }

    #[Test]
    public function failsWhenNoBrowserIsInstalledThere(): void
    {
        $path = '/var/www/html/' . uniqid('cache', true);

        $result = (new BrowsersPath($path))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('chromium', $result->detail);
    }

    #[Test]
    public function measuresThePathAgainstTheProjectItRunsIn(): void
    {
        $result = (new BrowsersPath('/opt/site/.cache/ms-playwright', projectRoot: '/opt/site'))->run();

        self::assertStringContainsString('chromium', $result->detail);
    }

    #[Test]
    public function passesWhenTheRunLivesInAnotherContainer(): void
    {
        $result = (new BrowsersPath(null, runsElsewhere: true))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('another container', $result->detail);
    }

    #[Test]
    public function passesWithNoLocalBrowsersWhenABrowserServerAnswers(): void
    {
        $result = (new BrowsersPath(null, 'ws://playwright-server:3000/'))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('playwright-server:3000', $result->detail);
    }
}
