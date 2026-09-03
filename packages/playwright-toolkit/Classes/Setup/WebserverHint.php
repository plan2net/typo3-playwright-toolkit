<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class WebserverHint
{
    /**
     * @return list<string>
     */
    public static function forWebserver(string $webserverType, string $projectPath): array
    {
        if (str_starts_with($webserverType, 'nginx')) {
            return self::forNginx($projectPath);
        }

        if (is_file($projectPath . '/.ddev/apache/context.conf')) {
            return ['ddev restart'];
        }

        return ['.ddev/apache/context.conf decides the context, and it is missing.'];
    }

    /**
     * @return list<string>
     */
    private static function forNginx(string $projectPath): array
    {
        foreach (glob($projectPath . '/.ddev/nginx/*.conf') ?: [] as $snippet) {
            if (str_contains((string) file_get_contents($snippet), 'TYPO3_CONTEXT')) {
                return [
                    $snippet . ' sets TYPO3_CONTEXT, and DDEV includes that directory',
                    'after the PHP location, so nginx drops the value. It belongs in',
                    '.ddev/nginx_full/nginx-site.conf instead. See step 1 of SETUP.md.',
                ];
            }
        }

        return ['Nothing sets TYPO3_CONTEXT for nginx. See step 1 of SETUP.md.'];
    }
}
