<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Result;

final class ResultTest extends TestCase
{
    #[Test]
    public function turnsAThrownMessageIntoTheFailureReason(): void
    {
        $result = Result::of(static function (): string {
            throw new \RuntimeException(
                'The Playwright test database template is missing. Run "ddev playwright-prepare" to build it.'
            );
        });

        self::assertFalse($result->passed);
        self::assertStringContainsString('ddev playwright-prepare', $result->detail);
    }

    #[Test]
    public function namesTheFilesAFailureWantsWritten(): void
    {
        $result = Result::fail('tsconfig.json and .gitignore missing', 'tsconfig.json', '.gitignore');

        self::assertSame(['tsconfig.json', '.gitignore'], $result->missingFiles);
    }

    #[Test]
    public function keepsWhatTheAssertionReturnedAsTheDetail(): void
    {
        $result = Result::of(static fn(): string => 'fingerprint 4f2a');

        self::assertTrue($result->passed);
        self::assertSame('fingerprint 4f2a', $result->detail);
    }
}
