<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\SiteName;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteNameTest extends FunctionalTestCase
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
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'Acme';
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY], $_COOKIE[TestContext::TEST_ID_COOKIE]);

        parent::tearDown();
    }

    #[Test]
    public function leavesTheSiteNameAloneForATestRun(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        (new SiteName())(new BootCompletedEvent(false));

        self::assertSame('Acme', $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
    }

    #[Test]
    public function marksTheSiteNameForAnInspectSession(): void
    {
        $_COOKIE[TestContext::TEST_ID_COOKIE] = self::TEST_ID;

        (new SiteName())(new BootCompletedEvent(false));

        self::assertSame('Acme [' . self::TEST_ID . ']', $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
    }
}
