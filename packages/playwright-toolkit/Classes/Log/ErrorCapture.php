<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Log;

use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;

final class ErrorCapture
{
    public static function register(): void
    {
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['LOG']) || !\is_array($GLOBALS['TYPO3_CONF_VARS']['LOG'])) {
            $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];
        }

        $configuration = &$GLOBALS['TYPO3_CONF_VARS']['LOG'];
        self::addWriter($configuration);
        self::addToNested($configuration);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function addToNested(array &$node): void
    {
        foreach ($node as $key => &$child) {
            if ('writerConfiguration' === $key || !\is_array($child)) {
                continue;
            }

            // LogManager takes the most specific non-empty configuration instead of
            // merging, so creating one here would shadow the root for that logger.
            if (isset($child['writerConfiguration'])) {
                self::addWriter($child);
            }

            self::addToNested($child);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function addWriter(array &$node): void
    {
        $node['writerConfiguration'][LogLevel::ERROR][DatabaseWriter::class] ??= [];
    }
}
