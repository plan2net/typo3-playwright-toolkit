<?php

// settings.php is written by `typo3 setup`, so everything this fixture owns lives
// here instead — this file is never rewritten.

use TYPO3\CMS\Core\Core\Environment;

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
    'fixturesPath' => 'fixtures',
    'fixtureManifest' => '010-root-page.sql',
];

// Two hostnames answer here and only one is the Testing one.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';

// TYPO3 loads only this file, never a context-suffixed one, so the Testing
// configuration is reached from here or not at all.
if (Environment::getContext()->isTesting()) {
    require __DIR__ . '/additional-testing.php';
}
