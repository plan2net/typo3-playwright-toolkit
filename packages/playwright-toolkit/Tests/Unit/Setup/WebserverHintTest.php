<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\WebserverHint;

final class WebserverHintTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/playwright-webserver-' . uniqid('', true);
        mkdir($this->projectPath . '/.ddev', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectPath . '/.ddev/{,*/}*.conf', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->projectPath . '/.ddev/*', GLOB_ONLYDIR) ?: [] as $directory) {
            rmdir($directory);
        }
        rmdir($this->projectPath . '/.ddev');
        rmdir($this->projectPath);
    }

    #[Test]
    public function saysWhyANginxSnippetIsIgnored(): void
    {
        mkdir($this->projectPath . '/.ddev/nginx');
        file_put_contents(
            $this->projectPath . '/.ddev/nginx/context.conf',
            "fastcgi_param TYPO3_CONTEXT Testing;\n"
        );

        $hint = implode("\n", WebserverHint::forWebserver('nginx-fpm', $this->projectPath));

        self::assertStringContainsString('after the PHP location', $hint);
        self::assertStringContainsString('nginx_full', $hint);
    }

    #[Test]
    public function namesTheApacheFileThatIsMissing(): void
    {
        $hint = WebserverHint::forWebserver('apache-fpm', $this->projectPath);

        self::assertStringContainsString('.ddev/apache/context.conf', implode("\n", $hint));
    }

    #[Test]
    public function tellsApacheToRestartOnceItsContextFileIsThere(): void
    {
        mkdir($this->projectPath . '/.ddev/apache');
        file_put_contents(
            $this->projectPath . '/.ddev/apache/context.conf',
            "SetEnvIf Host \"-testing\\.ddev\\.site$\" TYPO3_CONTEXT=Testing\n"
        );

        self::assertSame(
            ['ddev restart'],
            WebserverHint::forWebserver('apache-fpm', $this->projectPath)
        );
    }
}
