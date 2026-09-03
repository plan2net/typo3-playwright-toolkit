<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\FileWriter;

final class FileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-writer-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,*/}{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            rmdir($sub);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function fillsThePlaceholdersOfATemplate(): void
    {
        $writer = new FileWriter($this->directory, [
            'TESTING_URL' => 'https://example-testing.ddev.site',
            'PROJECT_ROOT' => '../..',
        ]);

        $writer->write('playwright.config.ts');

        $written = (string) file_get_contents($this->directory . '/playwright.config.ts');
        self::assertStringContainsString("testingURL: 'https://example-testing.ddev.site'", $written);
        self::assertStringContainsString("new URL('../..', import.meta.url)", $written);
    }

    #[Test]
    public function makesTheDirectoryATargetNeeds(): void
    {
        $writer = new FileWriter($this->directory, ['ROOT_PAGE_ID' => '42']);

        $writer->write('tests/first.spec.ts');

        self::assertStringContainsString(
            'atParentId(42)',
            (string) file_get_contents($this->directory . '/tests/first.spec.ts')
        );
    }

    #[Test]
    public function writesTheDotFileFromItsUndottedTemplate(): void
    {
        (new FileWriter($this->directory))->write('.gitignore');

        self::assertStringContainsString(
            'test-results/',
            (string) file_get_contents($this->directory . '/.gitignore')
        );
    }

    #[Test]
    public function refusesATargetDirectoryOutsideTheProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileWriter($this->directory . '/../../elsewhere', [], $this->directory);
    }

    // A project's own manifest names files this package has no template for.
    #[Test]
    public function refusesAFileItHasNoTemplateFor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FileWriter($this->directory))->write('020-content.sql');
    }

    #[Test]
    public function refusesANameThatClimbsOutOfTheTargetDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FileWriter($this->directory))->write('../../escaped.json');
    }

    #[Test]
    public function leavesAFileThatAlreadyExistsAlone(): void
    {
        file_put_contents($this->directory . '/tsconfig.json', "{ \"mine\": true }\n");

        (new FileWriter($this->directory))->write('tsconfig.json');

        self::assertSame(
            "{ \"mine\": true }\n",
            file_get_contents($this->directory . '/tsconfig.json')
        );
    }
}
