<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\TestContext;
use Plan2net\PlaywrightToolkit\Tests\Functional\Imaging\Fixtures\ProjectGifBuilder;
use Plan2net\PlaywrightToolkit\Tests\Functional\Imaging\Fixtures\ProjectGraphicalFunctions;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProjectXclassTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'Objects' => [
                GraphicalFunctions::class => ['className' => ProjectGraphicalFunctions::class],
                GifBuilder::class => ['className' => ProjectGifBuilder::class],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::flushInternalRuntimeCaches();
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        GeneralUtility::flushInternalRuntimeCaches();

        parent::tearDown();
    }

    #[Test]
    public function keepsTheProjectsGraphicalFunctionsOutsideATest(): void
    {
        self::assertInstanceOf(ProjectGraphicalFunctions::class, GeneralUtility::makeInstance(GraphicalFunctions::class));
    }

    #[Test]
    public function scopesTheProjectsGraphicalFunctionsInATest(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);

        self::assertInstanceOf(ProjectGraphicalFunctions::class, $graphicalFunctions);
        self::assertStringStartsWith('ABCD1234EFGH5678-', $graphicalFunctions->filenamePrefix);
    }

    #[Test]
    public function keepsTheProjectsGifBuilderOutsideATest(): void
    {
        self::assertInstanceOf(ProjectGifBuilder::class, GeneralUtility::makeInstance(GifBuilder::class));
    }

    #[Test]
    public function keepsTheProjectsGifBuilderInATest(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        self::assertInstanceOf(ProjectGifBuilder::class, GeneralUtility::makeInstance(GifBuilder::class));
    }
}
