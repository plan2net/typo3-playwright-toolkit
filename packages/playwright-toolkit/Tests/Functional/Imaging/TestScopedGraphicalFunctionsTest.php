<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TestScopedGraphicalFunctionsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        parent::tearDown();
    }

    #[Test]
    public function coreAsksTheContainerForTheClassThisPackageRegistered(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);

        self::assertSame('ABCD1234EFGH5678-', $graphicalFunctions->filenamePrefix);
    }

    #[Test]
    public function twoTestIdsNeverShareAScratchName(): void
    {
        $prefixes = [];
        foreach (['ABCD1234EFGH5678', 'ZYXW9876VUTS5432'] as $testId) {
            $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $testId;
            $prefixes[] = GeneralUtility::makeInstance(GraphicalFunctions::class)->filenamePrefix;
        }

        self::assertNotSame($prefixes[0], $prefixes[1]);
    }

    #[Test]
    public function aRequestWithoutATestIdKeepsCoreBehaviour(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        self::assertSame('', GeneralUtility::makeInstance(GraphicalFunctions::class)->filenamePrefix);
    }

    #[Test]
    public function aMalformedTestIdNamesNothing(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = '../../etc';

        self::assertSame('', GeneralUtility::makeInstance(GraphicalFunctions::class)->filenamePrefix);
    }
}
