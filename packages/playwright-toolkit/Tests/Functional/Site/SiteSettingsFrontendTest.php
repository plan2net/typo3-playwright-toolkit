<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Site;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Site\SiteSettingsOverlay;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteSettingsFrontendTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(SiteSettings::class)) {
            self::markTestSkipped('TYPO3 11.5 reads the site for TypoScript through the SiteFinder, not the request');
        }

        $this->importCSVDataSet(__DIR__ . '/Fixtures/RootPage.csv');
        $this->setUpFrontendRootPage(1, ['EXT:playwright_toolkit/Tests/Functional/Site/Fixtures/look.typoscript']);
        GeneralUtility::mkdir_deep(Environment::getConfigPath() . '/sites/shop');
        GeneralUtility::writeFile(
            Environment::getConfigPath() . '/sites/shop/config.yaml',
            "rootPageId: 1\nbase: 'https://shop.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\nsettings:\n  theme:\n    look: calm\n"
        );
    }

    #[Test]
    public function rendersTheSiteValueWhenNothingIsStored(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://shop.test/'));

        self::assertStringContainsString('look:calm', (string) $response->getBody());
    }

    #[Test]
    public function rendersTheOverlaidValueAsATypoScriptConstant(): void
    {
        $this->get(Registry::class)->set(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop', ['theme.look' => 'wild']);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://shop.test/'));

        self::assertStringContainsString('look:wild', (string) $response->getBody());
    }
}
