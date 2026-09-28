<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\SiteList;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteListTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function listsEverySiteWithTheBaseItsTestingVariantSelects(): void
    {
        $sites = [
            'corporate' => self::site('corporate', 1, 'https://corporate.example/', 'https://corporate-testing.ddev.site/'),
            'shop' => self::site('shop', 2573, 'https://shop.example/', 'https://shop-testing.ddev.site/'),
        ];

        self::assertSame(ContractFixture::read('health-sites')['sites'], SiteList::from($sites));
    }

    private static function site(string $identifier, int $rootPageId, string $base, string $testingBase): Site
    {
        return new Site($identifier, $rootPageId, [
            'base' => $base,
            'baseVariants' => [
                ['base' => $testingBase, 'condition' => 'applicationContext == "Testing"'],
            ],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ],
        ]);
    }
}
