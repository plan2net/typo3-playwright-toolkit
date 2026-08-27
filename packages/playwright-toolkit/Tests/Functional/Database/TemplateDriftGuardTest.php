<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\TemplateDriftGuard;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TemplateDriftGuardTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'DRIFTGUARD123456';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseInitializer::forgetClone();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'the-encryption-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '1',
        ];
    }

    protected function tearDown(): void
    {
        DatabaseInitializer::forgetClone();
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        foreach (glob(Environment::getVarPath() . '/test-databases/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function failsTheRequestThatClonedFromADriftedTemplate(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    #[Test]
    public function theNextRequestMustNotRunOnTheRejectedClone(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        try {
            $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
            self::fail('Expected the first request to be rejected.');
        } catch (\RuntimeException) {
            // the rejection is the precondition
        }

        DatabaseInitializer::forgetClone();
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    #[Test]
    public function staysSilentWhenThisRequestClonedNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver(Environment::getVarPath() . '/test-databases');
    }
}
