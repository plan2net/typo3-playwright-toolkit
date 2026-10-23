<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteWithSettings
{
    /**
     * @return array<array-key, mixed>
     */
    public static function settingsOf(Site $site): array
    {
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($site, 'getSettings')) {
            return $site->getSettings()->getAll();
        }

        $settings = $site->getConfiguration()['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    public static function create(Site $site, array $settings): Site
    {
        $configuration = $site->getConfiguration();
        $configuration['settings'] = $settings;
        // @phpstan-ignore function.alreadyNarrowedType
        if (!method_exists($site, 'getTSconfig')) {
            return new Site($site->getIdentifier(), $site->getRootPageId(), $configuration);
        }

        return new Site($site->getIdentifier(), $site->getRootPageId(), $configuration, null, $site->getTypoScript(), $site->getTSconfig());
    }
}
