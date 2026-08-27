<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;

final class TestLabelTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/playwright-label-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function readsBackTheLabelItWrote(): void
    {
        $lockFiles = new LockFiles($this->directory);
        $lockFiles->writeLabel('dbABCD1234EFGH5678', 'accordion-simple');

        self::assertSame('accordion-simple', $lockFiles->readLabel('dbABCD1234EFGH5678'));
    }

    #[Test]
    public function answersEmptyForADatabaseNobodyLabelled(): void
    {
        $lockFiles = new LockFiles($this->directory);

        self::assertSame('', $lockFiles->readLabel('dbABCD1234EFGH5678'));
    }

    #[Test]
    public function keepsTheClaimItselfIntact(): void
    {
        $lockFiles = new LockFiles($this->directory);
        touch($lockFiles->claim('dbABCD1234EFGH5678'));

        $lockFiles->writeLabel('dbABCD1234EFGH5678', 'accordion-simple');

        self::assertFileExists($lockFiles->claim('dbABCD1234EFGH5678'));
    }

    #[Test]
    public function replacesALabelRatherThanAppending(): void
    {
        $lockFiles = new LockFiles($this->directory);
        $lockFiles->writeLabel('dbABCD1234EFGH5678', 'first');
        $lockFiles->writeLabel('dbABCD1234EFGH5678', 'second');

        self::assertSame('second', $lockFiles->readLabel('dbABCD1234EFGH5678'));
    }

    #[Test]
    public function refusesALabelThatIsNotOnOneLine(): void
    {
        $lockFiles = new LockFiles($this->directory);
        $lockFiles->writeLabel('dbABCD1234EFGH5678', "first\nsecond");

        self::assertSame('first second', $lockFiles->readLabel('dbABCD1234EFGH5678'));
    }
}
