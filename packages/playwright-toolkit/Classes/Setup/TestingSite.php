<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class TestingSite
{
    /**
     * @param array<string, array<string, mixed>> $sites
     */
    public static function for(array $sites, string $testingUrl): ?string
    {
        if (1 === \count($sites)) {
            return (string) array_key_first($sites);
        }

        $host = parse_url($testingUrl, PHP_URL_HOST);
        $matches = array_keys(array_filter(
            $sites,
            static fn(array $configuration): bool => \in_array($host, self::hosts($configuration), true)
        ));

        return 1 === \count($matches) ? (string) $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return list<string>
     */
    private static function hosts(array $configuration): array
    {
        $bases = [$configuration['base'] ?? ''];
        foreach ((array) ($configuration['baseVariants'] ?? []) as $variant) {
            $bases[] = \is_array($variant) ? ($variant['base'] ?? '') : '';
        }

        $hosts = [];
        foreach ($bases as $base) {
            $base = (string) $base;
            // TYPO3 reads a base like "example.com/" as a host name.
            if (!str_contains($base, '//') && !str_starts_with($base, '/')) {
                $base = '//' . $base;
            }
            $host = parse_url($base, PHP_URL_HOST);
            if (\is_string($host)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
