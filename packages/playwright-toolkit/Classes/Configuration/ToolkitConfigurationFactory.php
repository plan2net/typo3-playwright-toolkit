<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ToolkitConfigurationFactory
{
    /**
     * @var string
     */
    private const EXTENSION_KEY = 'playwright_toolkit';

    /**
     * The single source of every default — ext_conf_template.txt only mirrors
     * these for the backend's configuration module.
     *
     * @var array<string, string|int>
     */
    private const DEFAULTS = [
        'fixturesPath' => '',
        'fixtureManifest' => '',
        'preseededSessionId' => 'playwright_test_session',
        'sessionUserId' => 1,
        'cleanupMinimumAgeMs' => 3600000,
    ];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function create(): ToolkitConfiguration
    {
        $configuration = $this->readConfiguration();

        return new ToolkitConfiguration(
            fixturesPath: (string) $this->value($configuration, 'fixturesPath'),
            fixtureManifest: ToolkitConfiguration::parseList((string) $this->value($configuration, 'fixtureManifest')),
            preseededSessionId: (string) $this->value($configuration, 'preseededSessionId'),
            sessionUserId: (int) $this->value($configuration, 'sessionUserId'),
            cleanupMinimumAgeMs: (int) $this->value($configuration, 'cleanupMinimumAgeMs'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfiguration(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            return [];
        }

        return is_array($configuration) ? $configuration : [];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function value(array $configuration, string $key): string|int
    {
        $configured = $configuration[$key] ?? null;
        if (null === $configured || '' === $configured) {
            return self::DEFAULTS[$key];
        }

        return $configured;
    }
}
