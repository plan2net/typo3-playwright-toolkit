<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExtensionBootTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function bootsAndResolvesItsServicesFromTheContainer(): void
    {
        self::assertInstanceOf(
            ToolkitConfigurationFactory::class,
            $this->get(ToolkitConfigurationFactory::class)
        );
        self::assertInstanceOf(
            BackendSessionProvider::class,
            $this->get(BackendSessionProvider::class)
        );
    }
}
