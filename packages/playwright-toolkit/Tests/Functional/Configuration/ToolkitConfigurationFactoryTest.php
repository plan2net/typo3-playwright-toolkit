<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ToolkitConfigurationFactoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function appliesGenericDefaultsWhenNothingIsConfigured(): void
    {
        $factory = new ToolkitConfigurationFactory($this->get(ExtensionConfiguration::class));

        $configuration = $factory->create();

        self::assertSame('', $configuration->fixturesPath);
        self::assertSame([], $configuration->fixtureManifest);
        self::assertSame('playwright_test_session', $configuration->preseededSessionId);
        self::assertSame(1, $configuration->sessionUserId);
        self::assertSame(3600000, $configuration->cleanupMinimumAgeMs);
        self::assertSame('', $configuration->mediaPath);
        self::assertSame('', $configuration->mediaStorage);
    }

    // Empty provisions a storage of our own; a combined identifier names one the
    // project already declares.
    #[Test]
    public function readsAConfiguredMediaStorage(): void
    {
        $this->get(ExtensionConfiguration::class)
            ->set('playwright_toolkit', ['mediaStorage' => '1:/playwright-media/']);

        $configuration = (new ToolkitConfigurationFactory($this->get(ExtensionConfiguration::class)))->create();

        self::assertSame('1:/playwright-media/', $configuration->mediaStorage);
    }

    // Empty switches media seeding off entirely, so the default has to stay empty.
    #[Test]
    public function readsAConfiguredMediaPath(): void
    {
        $this->get(ExtensionConfiguration::class)
            ->set('playwright_toolkit', ['mediaPath' => 'tests/playwright/fixtures/media']);

        $configuration = (new ToolkitConfigurationFactory($this->get(ExtensionConfiguration::class)))->create();

        self::assertSame('tests/playwright/fixtures/media', $configuration->mediaPath);
    }

    // A consumer may widen the sweep floor; the endpoint clamps requests up to it.
    #[Test]
    public function readsAConfiguredCleanupFloor(): void
    {
        $this->get(ExtensionConfiguration::class)->set('playwright_toolkit', ['cleanupMinimumAgeMs' => '7200000']);

        $configuration = (new ToolkitConfigurationFactory($this->get(ExtensionConfiguration::class)))->create();

        self::assertSame(7200000, $configuration->cleanupMinimumAgeMs);
    }

    #[Test]
    public function parsesConfiguredListsAndOverridesInOrder(): void
    {
        $configurationToWrite = [
            'fixturesPath' => 'custom/fixtures',
            'fixtureManifest' => 'pages.sql, news.sql ,file-storage.sql',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '7',
        ];
        $this->get(ExtensionConfiguration::class)->set('playwright_toolkit', $configurationToWrite);

        $factory = new ToolkitConfigurationFactory($this->get(ExtensionConfiguration::class));
        $configuration = $factory->create();

        self::assertSame('custom/fixtures', $configuration->fixturesPath);
        self::assertSame(['pages.sql', 'news.sql', 'file-storage.sql'], $configuration->fixtureManifest);
        self::assertSame(7, $configuration->sessionUserId);
    }
}
