<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Answers;

final class AnswersTest extends TestCase
{
    #[Test]
    public function refusesATestDirectoryThatClimbsOutOfTheProject(): void
    {
        self::assertStringContainsString(
            '..',
            (string) Answers::testDirectoryProblem('../../etc/playwright')
        );
    }

    #[Test]
    public function refusesAnAbsoluteTestDirectory(): void
    {
        self::assertNotNull(Answers::testDirectoryProblem('/var/www/html/tests'));
    }

    #[Test]
    public function refusesATestDirectoryWithAShellCharacter(): void
    {
        self::assertNotNull(Answers::testDirectoryProblem('tests; rm -rf /'));
    }

    #[Test]
    public function refusesAnEmptyTestDirectory(): void
    {
        self::assertNotNull(Answers::testDirectoryProblem(''));
    }

    #[Test]
    public function acceptsTheDefaultTestDirectory(): void
    {
        self::assertNull(Answers::testDirectoryProblem('tests/playwright'));
    }

    #[Test]
    public function countsOnlyRealSegmentsBackToTheProjectRoot(): void
    {
        self::assertSame('../..', Answers::relativeProjectRoot('./tests//playwright/'));
    }

    #[Test]
    public function refusesATestingUrlWithAPath(): void
    {
        self::assertNotNull(Answers::testingUrlProblem('https://example-testing.ddev.site/subdir'));
    }

    #[Test]
    public function refusesATestingUrlWithoutAScheme(): void
    {
        self::assertStringContainsString(
            'absolute',
            (string) Answers::testingUrlProblem('example-testing.ddev.site')
        );
    }

    #[Test]
    public function refusesATestingUrlWithCredentials(): void
    {
        self::assertStringContainsString(
            'password',
            (string) Answers::testingUrlProblem('https://user:pass@example-testing.ddev.site')
        );
    }

    #[Test]
    public function refusesATestingUrlWithAQueryString(): void
    {
        self::assertStringContainsString(
            'query',
            (string) Answers::testingUrlProblem('https://example-testing.ddev.site?debug=1')
        );
    }

    #[Test]
    public function refusesATestingUrlThatIsNotHttp(): void
    {
        self::assertStringContainsString(
            'http',
            (string) Answers::testingUrlProblem('ftp://example-testing.ddev.site')
        );
    }

    #[Test]
    public function refusesATestingUrlWhoseHostCouldBreakOutOfTheConfig(): void
    {
        self::assertNotNull(Answers::testingUrlProblem("https://example'-testing.ddev.site"));
    }

    #[Test]
    public function acceptsATestingUrlWithAPort(): void
    {
        self::assertNull(Answers::testingUrlProblem('http://localhost:8080'));
    }
}
