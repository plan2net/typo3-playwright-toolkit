<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\BorrowedConnection;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\SeedSources;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseInitializerGuardsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private ?string $originalTestId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTestId = $_SERVER[TestContext::TEST_ID_SERVER_KEY] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalTestId) {
            unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        } else {
            $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $this->originalTestId;
        }
        parent::tearDown();
    }

    #[Test]
    public function provisionsNothingWhenNoTestIdHeaderIsPresent(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $this->guardedInitializer()->__invoke(new BootCompletedEvent(true));

        self::assertDirectoryDoesNotExist(Environment::getVarPath() . '/test-locks');
    }

    // Every functional test runs on the CLI, so this is the guard that fires here;
    // the test-ID guard above cannot mask it because it is checked first.
    #[Test]
    public function provisionsNothingOnTheCliEvenWithATestId(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        $this->guardedInitializer()->__invoke(new BootCompletedEvent(true));

        self::assertDirectoryDoesNotExist(Environment::getVarPath() . '/test-locks');
    }

    /**
     * This listener creates and drops databases, so the context gate is the most
     * important line in the class. Development must fail it as firmly as
     * Production — isTesting() matches a root context of Testing only.
     */
    #[Test]
    #[DataProvider('contextsThatMustNeverProvision')]
    public function provisionsNothingOutsideATestingContext(string $context): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        $this->inContext($context, function (): void {
            $this->guardedInitializer()->__invoke(new BootCompletedEvent(true));
        });

        self::assertDirectoryDoesNotExist(Environment::getVarPath() . '/test-locks');
    }

    /**
     * A valid test ID is all it takes to make a request provision a database, so
     * without this an unauthenticated caller can create one at will — and then
     * hold a session against it.
     */
    #[Test]
    public function provisionsNothingForAWebRequestWithoutTheSecret(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';
        unset($_SERVER[TestApiSecret::SERVER_KEY]);

        $this->asWebRequest(function (): void {
            $this->guardedInitializer()->__invoke(new BootCompletedEvent(true));
        });

        self::assertDirectoryDoesNotExist(Environment::getVarPath() . '/test-locks');
    }

    #[Test]
    public function provisionsNothingForAWebRequestWithTheWrongSecret(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';
        $this->get(TestApiSecret::class)->ensureExists();
        $_SERVER[TestApiSecret::SERVER_KEY] = 'not-the-secret';

        $this->asWebRequest(function (): void {
            $this->guardedInitializer()->__invoke(new BootCompletedEvent(true));
        });

        self::assertDirectoryDoesNotExist(Environment::getVarPath() . '/test-locks');
        @unlink($this->get(TestApiSecret::class)->file());
        unset($_SERVER[TestApiSecret::SERVER_KEY]);
    }

    /**
     * @return list<array{string}>
     */
    public static function contextsThatMustNeverProvision(): array
    {
        return [['Production'], ['Production/Staging'], ['Development'], ['Development/Local']];
    }

    private function asWebRequest(callable $body): void
    {
        $originalIsCli = Environment::isCli();
        $this->reinitializeWith(Environment::getContext(), false);

        try {
            $body();
        } finally {
            $this->reinitializeWith(Environment::getContext(), $originalIsCli);
        }
    }

    /**
     * Runs the body as a *web* request. Functional tests are otherwise always CLI,
     * and the CLI guard would then stop provisioning whatever the context said —
     * masking the very gate these tests exist to pin.
     */
    private function inContext(string $context, callable $body): void
    {
        $originalContext = Environment::getContext();
        $originalIsCli = Environment::isCli();
        $this->reinitializeWith(new ApplicationContext($context), false);

        try {
            $body();
        } finally {
            $this->reinitializeWith($originalContext, $originalIsCli);
        }
    }

    private function reinitializeWith(ApplicationContext $context, bool $isCli): void
    {
        Environment::initialize(
            $context,
            $isCli,
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );
    }

    // ToolkitConfigurationFactory::create() is the first thing past the guards, so
    // an extension configuration that is never read pins them to that exact spot.
    private function guardedInitializer(): DatabaseInitializer
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::never())->method('get');

        return new DatabaseInitializer(
            new ToolkitConfigurationFactory($extensionConfiguration),
            $this->get(SeedSources::class),
            LockFiles::inVarPath(),
            $this->get(TestApiSecret::class),
            new BorrowedConnection($this->get(ConnectionPool::class))
        );
    }
}
