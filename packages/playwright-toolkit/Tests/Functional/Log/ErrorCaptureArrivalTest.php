<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Log;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Log\ErrorCapture;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * LogManager swallows a bad level key, so asserting the configuration would pass
 * while nothing is ever written.
 */
final class ErrorCaptureArrivalTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const MESSAGE = 'something an extension reported';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function anErrorReachesSysLogEvenForALoggerCoreConfiguresItself(): void
    {
        ErrorCapture::register();

        // Core gives this logger notices and a file, and no database — so a row
        // here can only come from the walk that reaches nested configurations.
        $this->get(LogManager::class)->getLogger('TYPO3.CMS.deprecations')->error(self::MESSAGE);

        self::assertContains(self::MESSAGE, $this->messagesInSysLog());
    }

    /**
     * @return list<string>
     */
    private function messagesInSysLog(): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->executeQuery("SELECT message FROM sys_log WHERE component <> ''")
            ->fetchAllAssociative();

        return array_map(static fn(array $row): string => (string) $row['message'], $rows);
    }
}
