<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\FileWriter;

/**
 * The wizard writes these files and SETUP.md shows them, so the two must say the same.
 */
final class GuideTemplateTest extends TestCase
{
    /**
     * The values SETUP.md uses in its examples.
     *
     * @var array<string, string>
     */
    private const GUIDE_VALUES = [
        'TESTING_URL' => 'https://example-testing.ddev.site',
        'PROJECT_ROOT' => '../..',
        'ROOT_PAGE_ID' => '1',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function writtenFiles(): iterable
    {
        yield 'playwright.config.ts' => ['playwright.config.ts'];
        yield 'tsconfig.json' => ['tsconfig.json'];
        yield '.gitignore' => ['.gitignore'];
        yield 'first.spec.ts' => ['tests/first.spec.ts'];
        yield '010-root-page.sql' => ['fixtures/010-root-page.sql'];
    }

    #[Test]
    #[DataProvider('writtenFiles')]
    public function standsInSetupMdWordForWord(string $file): void
    {
        $guide = \dirname(__DIR__, 5) . '/SETUP.md';
        if (!is_file($guide)) {
            self::markTestSkipped('SETUP.md lives in the monorepo, not in the published package.');
        }

        $directory = sys_get_temp_dir() . '/playwright-guide-' . uniqid('', true);
        mkdir($directory, 0777, true);

        try {
            $written = (new FileWriter($directory, self::GUIDE_VALUES))->write($file);

            self::assertStringContainsString(
                rtrim((string) file_get_contents($written), "\n"),
                (string) file_get_contents($guide)
            );
        } finally {
            unlink($written ?? '');
            foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                rmdir($sub);
            }
            rmdir($directory);
        }
    }
}
