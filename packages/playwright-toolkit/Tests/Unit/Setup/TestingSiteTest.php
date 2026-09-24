<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\TestingSite;

final class TestingSiteTest extends TestCase
{
    #[Test]
    public function picksTheSiteWhoseTestingVariantNamesTheHost(): void
    {
        $sites = [
            'shop' => [
                'rootPageId' => 2573,
                'base' => 'https://shop.example/',
                'baseVariants' => [['base' => 'https://shop-testing.ddev.site/', 'condition' => 'applicationContext == "Testing"']],
            ],
            'main' => [
                'rootPageId' => 1,
                'base' => 'https://main.example/',
                'baseVariants' => [['base' => 'https://main-testing.ddev.site/', 'condition' => 'applicationContext == "Testing"']],
            ],
        ];

        self::assertSame('main', TestingSite::for($sites, 'https://main-testing.ddev.site'));
    }

    #[Test]
    public function readsABaseWithoutAScheme(): void
    {
        $sites = [
            'shop' => ['rootPageId' => 2573, 'base' => 'shop-testing.ddev.site/'],
            'main' => ['rootPageId' => 1, 'base' => 'main-testing.ddev.site/'],
        ];

        self::assertSame('main', TestingSite::for($sites, 'https://main-testing.ddev.site'));
    }

    #[Test]
    public function picksTheOnlySiteWhateverItsBase(): void
    {
        $sites = ['main' => ['rootPageId' => 1, 'base' => '/']];

        self::assertSame('main', TestingSite::for($sites, 'https://project-testing.ddev.site'));
    }
}
