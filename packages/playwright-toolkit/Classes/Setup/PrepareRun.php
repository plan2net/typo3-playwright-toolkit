<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class PrepareRun
{
    /**
     * A subprocess, because playwright:prepare only runs in the Testing context and
     * the wizard runs in whatever context the CLI has.
     *
     * @return list<string>
     */
    public static function commands(string $projectPath): array
    {
        $binary = escapeshellarg($projectPath . '/vendor/bin/typo3');

        return [
            'TYPO3_CONTEXT=Testing ' . $binary . ' cache:flush',
            'TYPO3_CONTEXT=Testing ' . $binary . ' playwright:prepare',
        ];
    }
}
