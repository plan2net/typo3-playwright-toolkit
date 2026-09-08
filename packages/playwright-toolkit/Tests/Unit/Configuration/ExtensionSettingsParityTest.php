<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;

final class ExtensionSettingsParityTest extends TestCase
{
    #[Test]
    public function theTemplateAndTheDefaultsNameTheSameSettings(): void
    {
        $template = self::templateSettings();
        $defaults = self::defaults();

        self::assertNotSame([], $template, 'nothing was parsed out of ext_conf_template.txt');
        self::assertSame(array_keys($template), array_keys($defaults));
    }

    #[Test]
    public function theTemplateAndTheDefaultsAgreeOnEveryValue(): void
    {
        self::assertSame(self::templateSettings(), self::defaults());
    }

    /**
     * @return array<string, string>
     */
    private static function templateSettings(): array
    {
        $file = \dirname(__DIR__, 3) . '/ext_conf_template.txt';

        $settings = [];
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=(.*)$/', trim($line), $matches)) {
                continue;
            }

            $settings[$matches[1]] = trim($matches[2]);
        }

        ksort($settings);

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        $property = new \ReflectionClassConstant(ToolkitConfigurationFactory::class, 'DEFAULTS');
        /** @var array<string, string|int> $defaults */
        $defaults = $property->getValue();

        $asStrings = array_map(strval(...), $defaults);
        ksort($asStrings);

        return $asStrings;
    }
}
