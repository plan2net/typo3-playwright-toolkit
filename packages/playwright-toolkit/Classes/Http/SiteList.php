<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteList
{
    /**
     * @param array<array-key, Site> $sites
     *
     * @return list<array{identifier: string, rootPageId: int, base: string}>
     */
    public static function from(array $sites): array
    {
        return array_values(array_map(
            static fn(Site $site): array => [
                'identifier' => $site->getIdentifier(),
                'rootPageId' => $site->getRootPageId(),
                'base' => (string) $site->getBase(),
            ],
            $sites
        ));
    }
}
