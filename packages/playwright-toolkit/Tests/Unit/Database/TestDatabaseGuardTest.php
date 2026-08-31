<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\TestDatabaseGuard;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Utility\ArrayUtility;

final class TestDatabaseGuardTest extends TestCase
{
    private const TEST_ID = 'ABCD1234EFGH5678';
    private const SECRET = 'the-secret';

    private string $secretFile = '';

    protected function setUp(): void
    {
        $this->secretFile = sys_get_temp_dir() . '/guard-secret-' . bin2hex(random_bytes(4));
        file_put_contents($this->secretFile, self::SECRET);

        Environment::initialize(
            new ApplicationContext('Testing'),
            false,
            true,
            '/app',
            '/app/public',
            '/app/var',
            '/app/config',
            '/app/public/index.php',
            'UNIX',
        );

        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $_SERVER[TestApiSecret::SERVER_KEY] = self::SECRET;
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY], $_SERVER[TestApiSecret::SERVER_KEY]);
        @unlink($this->secretFile);

        parent::tearDown();
    }

    #[Test]
    public function refusesARequestWhoseConnectionNamesAnotherDatabase(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_mysql',
            'dbname' => 'the_real_database',
        ];

        $this->expectExceptionMessageMatches('/db' . self::TEST_ID . '/');

        ($this->guard())(new BootCompletedEvent(false));
    }

    #[Test]
    public function saysNothingWhenTheConnectionNamesTheTestDatabase(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = ['driver' => 'pdo_sqlite'];
        foreach ((new SqliteTestDatabaseDriver('/app/var/test-databases'))->connectionOverrides(self::TEST_ID) as $path => $value) {
            $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path, $value);
        }

        $this->expectNotToPerformAssertions();

        ($this->guard())(new BootCompletedEvent(false));
    }

    private function guard(): TestDatabaseGuard
    {
        return new TestDatabaseGuard(new TestApiSecret($this->secretFile));
    }
}
