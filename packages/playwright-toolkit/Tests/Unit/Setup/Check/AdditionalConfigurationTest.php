<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\AdditionalConfiguration;

final class AdditionalConfigurationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/playwright-additional-' . uniqid('', true) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[Test]
    public function passesWhenTheFileCallsTheToolkit(): void
    {
        file_put_contents($this->file, "<?php\nTestContext::configureCurrentRequest();\n");

        self::assertTrue((new AdditionalConfiguration($this->file))->run()->passed);
    }

    #[Test]
    public function followsAnIncludeIntoTheFileThatCallsIt(): void
    {
        $included = \dirname($this->file) . '/' . basename($this->file, '.php') . '-testing.php';
        file_put_contents($included, "<?php\nTestContext::configureCurrentRequest();\n");
        file_put_contents(
            $this->file,
            "<?php\nif (true) {\n    @include_once __DIR__ . '/" . basename($included) . "';\n}\n"
        );

        try {
            self::assertTrue((new AdditionalConfiguration($this->file))->run()->passed);
        } finally {
            unlink($included);
        }
    }

    #[Test]
    public function failsWhenTheCallIsOnlyMentionedInAComment(): void
    {
        file_put_contents(
            $this->file,
            "<?php\n// TestContext::configureCurrentRequest() goes here once we get to it\n"
        );

        self::assertFalse((new AdditionalConfiguration($this->file))->run()->passed);
    }

    #[Test]
    public function failsWhenTheFileDoesNotCallTheToolkit(): void
    {
        file_put_contents($this->file, "<?php\n\$GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';\n");

        self::assertFalse((new AdditionalConfiguration($this->file))->run()->passed);
    }

    #[Test]
    public function passesWhenTheFileComposesTheSettingsItself(): void
    {
        file_put_contents($this->file, "<?php\nTestContext::resolveCurrentRequestSettings([]);\n");

        self::assertTrue((new AdditionalConfiguration($this->file))->run()->passed);
    }
}
