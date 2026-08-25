<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Configuration;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolkitConfigurationTest extends TestCase
{
    #[Test]
    public function parsesACommaSeparatedListPreservingOrderTrimmingAndDroppingEmpties(): void
    {
        $list = ToolkitConfiguration::parseList(' pages.sql , file-storage.sql ,, news.sql ');

        self::assertSame(['pages.sql', 'file-storage.sql', 'news.sql'], $list);
    }

    #[Test]
    public function parsesAnEmptyStringIntoAnEmptyList(): void
    {
        self::assertSame([], ToolkitConfiguration::parseList(''));
        self::assertSame([], ToolkitConfiguration::parseList('   '));
    }

    #[Test]
    public function exposesEveryConfiguredValueThroughItsAccessor(): void
    {
        $configuration = new ToolkitConfiguration(
            fixturesPath: 'some/fixtures',
            fixtureManifest: ['pages.sql', 'news.sql'],
            preseededSessionId: 'playwright_test_session',
            sessionUserId: 1,
            cleanupMinimumAgeMs: 3600000,
        );

        self::assertSame('some/fixtures', $configuration->fixturesPath);
        self::assertSame(['pages.sql', 'news.sql'], $configuration->fixtureManifest);
        self::assertSame('playwright_test_session', $configuration->preseededSessionId);
        self::assertSame(1, $configuration->sessionUserId);
        self::assertSame(3600000, $configuration->cleanupMinimumAgeMs);
    }
}
