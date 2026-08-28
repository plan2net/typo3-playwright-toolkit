<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Command\PrepareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PrepareCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_api'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob(Environment::getVarPath() . '/test-databases/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * The command drops and rebuilds the template, so Development must refuse it as
     * firmly as Production.
     */
    #[Test]
    #[DataProvider('contextsThatMustNeverPrepare')]
    public function refusesToBuildAnythingOutsideATestingContext(string $context): void
    {
        $originalContext = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $tester = new CommandTester($this->get(PrepareCommand::class));
            $exitCode = $tester->execute([]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('Testing', $tester->getDisplay());
            self::assertFileDoesNotExist(
                Environment::getVarPath() . '/test-databases/playwright_db_template.sqlite'
            );
        } finally {
            $this->reinitializeWith($originalContext);
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function contextsThatMustNeverPrepare(): array
    {
        return [['Production'], ['Development'], ['Development/Local']];
    }

    #[Test]
    public function buildsTheTemplateAndReportsTheFingerprint(): void
    {
        $tester = new CommandTester($this->get(PrepareCommand::class));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('fingerprint', $tester->getDisplay());
        self::assertFileExists(Environment::getVarPath() . '/test-databases/playwright_db_template.sqlite');
    }

    #[Test]
    public function warnsThatATemplateWithNoFixturesHoldsNoContent(): void
    {
        $tester = new CommandTester($this->get(PrepareCommand::class));

        $tester->execute([]);

        self::assertStringContainsString('fixtureManifest', $tester->getDisplay());
    }

    #[Test]
    public function aSecondRunReportsTheTemplateAsAlreadyCurrentInsteadOfRebuilding(): void
    {
        $tester = new CommandTester($this->get(PrepareCommand::class));
        $tester->execute([]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already current', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    #[Test]
    public function forceRebuildsATemplateThatIsAlreadyCurrent(): void
    {
        $tester = new CommandTester($this->get(PrepareCommand::class));
        $tester->execute([]);

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('fingerprint', $tester->getDisplay());
        self::assertStringNotContainsString('already current', $tester->getDisplay());
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
