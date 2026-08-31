<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Utility\ArrayUtility;

final class TestDatabaseGuard
{
    public function __construct(
        private readonly TestApiSecret $secret,
    ) {
    }

    public function __invoke(BootCompletedEvent $event): void
    {
        if (!Environment::getContext()->isTesting() || Environment::isCli()) {
            return;
        }

        $testId = TestContext::testId();

        // Only a real test run. An inspect session sends a cookie and no secret.
        if ('' === $testId || !$this->secret->matchesCurrentRequest()) {
            return;
        }

        /** @var array<string, mixed> $default */
        $default = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];

        foreach (TestDatabaseDriverFactory::fromConnection($default)->connectionOverrides($testId) as $path => $expected) {
            if ($this->currentValue($path) === (string) $expected) {
                continue;
            }

            // The overrides carry the password, so no values go in the message.
            throw new \RuntimeException(
                sprintf(
                    'Test %s should run against database "%s", but %s does not match. Something '
                    . 'set its own value after the toolkit did — merge '
                    . 'TestContext::resolveCurrentRequestSettings() last in '
                    . 'config/system/additional.php. Without it the test writes to the wrong database.',
                    $testId,
                    DatabaseName::forTestId($testId),
                    $path,
                ),
                1725100001
            );
        }
    }

    private function currentValue(string $path): string
    {
        try {
            return (string) ArrayUtility::getValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path);
        } catch (\Throwable) {
            return '';
        }
    }
}
