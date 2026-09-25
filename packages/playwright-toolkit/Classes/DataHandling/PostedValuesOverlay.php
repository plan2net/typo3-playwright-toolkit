<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\DataHandling;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PostedValuesOverlay implements FormDataProviderInterface
{
    /**
     * @var string
     */
    public const KEY = 'playwrightToolkitPostedValues';

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function addData(array $result): array
    {
        foreach ($result['customData'][self::KEY] ?? [] as $column => $value) {
            if (!isset($result['processedTca']['columns'][$column])) {
                continue;
            }

            if (\is_array($value)) {
                $stored = $result['databaseRow'][$column] ?? [];
                $stored = \is_array($stored) ? $stored : GeneralUtility::xml2array((string) $stored);
                $value = array_replace_recursive(\is_array($stored) ? $stored : [], $value);
            }

            $result['databaseRow'][$column] = $value;
        }

        return $result;
    }
}
