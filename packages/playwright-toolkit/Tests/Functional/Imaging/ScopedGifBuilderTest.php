<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * 11.5 and 12.4 run the whole crop/scale/mask conversion on GifBuilder; 13.4 and
 * 14.3 have it delegate to a GraphicalFunctions the container already scopes.
 */
final class ScopedGifBuilderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!property_exists(GifBuilder::class, 'filenamePrefix')) {
            self::markTestSkipped('This core converts through GraphicalFunctions.');
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        parent::tearDown();
    }

    #[Test]
    public function coreAsksTheContainerForTheClassThisPackageRegistered(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        self::assertStringStartsWith(self::TEST_ID . '-', GeneralUtility::makeInstance(GifBuilder::class)->filenamePrefix);
    }

    // The crop step replaces the prefix with 'crop_', which would name a scratch
    // file every parallel test shares.
    #[Test]
    public function keepsTheTestScopeWhenTheCallerReplacesThePrefix(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $gifBuilder = GeneralUtility::makeInstance(GifBuilder::class);
        $scope = $gifBuilder->filenamePrefix;

        $gifBuilder->filenamePrefix = 'crop_';
        $gifBuilder->imageMagickConvert('/does/not/exist.jpg', 'png');

        self::assertSame($scope . 'crop_', $gifBuilder->filenamePrefix);
    }

    #[Test]
    public function doesNotStackTheScopeAcrossConversions(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $gifBuilder = GeneralUtility::makeInstance(GifBuilder::class);
        $scope = $gifBuilder->filenamePrefix;

        $gifBuilder->imageMagickConvert('/does/not/exist.jpg', 'png');
        $gifBuilder->imageMagickConvert('/does/not/exist.jpg', 'png');

        self::assertSame($scope, $gifBuilder->filenamePrefix);
    }

    #[Test]
    public function aRequestWithoutATestIdKeepsCoreBehaviour(): void
    {
        self::assertSame('', GeneralUtility::makeInstance(GifBuilder::class)->filenamePrefix);
    }
}
