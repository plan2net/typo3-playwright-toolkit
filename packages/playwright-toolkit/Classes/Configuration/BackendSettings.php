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
    public static function cookieName(): string
    {
        return BackendUserAuthentication::getCookieName();
    }
}
