<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Compatibility\AtomicOutputGifBuilder;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AtomicOutputGifBuilderTest extends FunctionalTestCase
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

        if (property_exists(GifBuilder::class, 'filenamePrefix')) {
            self::markTestSkipped('This core converts on GifBuilder itself; see ScopedGifBuilderTest.');
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

        self::assertInstanceOf(AtomicOutputGifBuilder::class, GeneralUtility::makeInstance(GifBuilder::class));
    }

    #[Test]
    public function aRequestWithoutATestIdKeepsCoreBehaviour(): void
    {
        self::assertSame(GifBuilder::class, GeneralUtility::makeInstance(GifBuilder::class)::class);
    }
}
