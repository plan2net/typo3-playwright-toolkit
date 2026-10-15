<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http\Fixtures;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

final class LanguageReadingProvider implements FormDataProviderInterface
{
    /**
     * @var list<mixed>
     */
    public static array $languages = [];

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function addData(array $result): array
    {
        if ('tt_content' === $result['tableName']) {
            self::$languages[] = (array) ($result['databaseRow']['sys_language_uid'] ?? 0);
        }

        return $result;
    }
}
