<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit;

use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

final class SiteName
{
    /**
     * @var string
     */
    private const MARKER_PATTERN = '/ \[(?:(?:[^\]]* · )?[A-Z0-9]{16}|replay)\]$/u';

    /**
     * @var string
     */
    private const REPLAY_MARKER = 'replay';

    /**
     * @var string
     */
    private const NAME_SEPARATOR = ' · ';

    /** @psalm-suppress UnusedParam */
    public function __invoke(BootCompletedEvent $event): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        $testId = TestContext::testId();
        if ('' === $testId) {
            return;
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = self::marked(
            (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''),
            $testId,
            LockFiles::inVarPath()->readLabel(DatabaseName::forTestId($testId))
        );
    }

    public static function marked(string $siteName, string $testId, string $name = ''): string
    {
        $plain = (string) preg_replace(self::MARKER_PATTERN, '', $siteName);
        if ('' === $testId) {
            return $plain;
        }

        // It holds every scenario, so naming one of them says nothing.
        if (DatabaseName::REPLAY_TEST_ID === $testId) {
            return $plain . ' [' . self::REPLAY_MARKER . ']';
        }

        $marker = '' === $name ? $testId : $name . self::NAME_SEPARATOR . $testId;

        return $plain . ' [' . $marker . ']';
    }
}
