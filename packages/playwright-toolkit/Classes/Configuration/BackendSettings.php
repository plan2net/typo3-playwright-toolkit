<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Configuration;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Core settings a project may rename, read here rather than assumed. Not part of
 * ToolkitConfiguration: these belong to TYPO3, and the toolkit only follows them.
 */
final class BackendSettings
{
    /**
     * @var string
     */
    private const DEFAULT_ENTRY_POINT = '/typo3';

    public static function cookieName(): string
    {
        return BackendUserAuthentication::getCookieName();
    }

    /**
     * The path the backend routes answer under, without a trailing slash. TYPO3
     * 11.5 and 12.4 have no such setting, hence the default rather than a lookup.
     */
    public static function entryPoint(): string
    {
        $configured = (string) ($GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] ?? '');
        // Also accepts an absolute entry point, whose host the toolkit cannot follow.
        $path = trim((string) (parse_url($configured, PHP_URL_PATH) ?: ''), '/');

        return '' === $path ? self::DEFAULT_ENTRY_POINT : '/' . preg_replace('#/+#', '/', $path);
    }
}
