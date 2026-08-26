<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Command;

use Plan2net\PlaywrightToolkit\Command\ReplayPrepareCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReplayPrepareCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    #[DataProvider('contextsThatMustNeverReplay')]
    public function refusesToReplaceAnythingOutsideATestingContext(string $context): void
    {
        $originalContext = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $tester = new CommandTester($this->get(ReplayPrepareCommand::class));

            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('Testing', $tester->getDisplay());
        } finally {
            $this->reinitializeWith($originalContext);
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function contextsThatMustNeverReplay(): array
    {
        return [['Production'], ['Development'], ['Development/Local']];
    }

    // The suite runs on sqlite, which replay refuses: a message, not a stack trace.
    #[Test]
    public function reportsWhyItCannotReplay(): void
    {
        $tester = new CommandTester($this->get(ReplayPrepareCommand::class));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('db-test', $tester->getDisplay());
    }

    private function reinitializeWith(ApplicationContext $context): void
    {
        Environment::initialize(
            $context,
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );
    }
}
