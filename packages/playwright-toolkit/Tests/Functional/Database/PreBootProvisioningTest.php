<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PreBootProvisioningTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'PREBOOT123456789';
    /**
     * @var string
     */
    private const SESSION_ID = 'playwright_test_session';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    /** @var array<string, mixed> */
    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'the-encryption-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
            'preseededSessionId' => self::SESSION_ID,
            'sessionUserId' => '1',
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = $this->originalConnections;
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY], $_SERVER[TestApiSecret::SERVER_KEY]);
        @unlink($this->get(TestApiSecret::class)->file());

        foreach (glob(Environment::getVarPath() . '/test-databases/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function redirectingTheConnectionAlsoCreatesTheTestDatabase(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $_SERVER[TestApiSecret::SERVER_KEY] = $this->get(TestApiSecret::class)->ensureExists();

        $this->asWebRequest(static function (): void {
            TestContext::applyDatabaseConnectionOverrides();
        });

        self::assertFileExists(
            Environment::getVarPath() . '/test-databases/db' . self::TEST_ID . '.sqlite'
        );
    }

    #[Test]
    public function theProjectConnectionKeepsTheKeysTheOverridesDoNotName(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $_SERVER[TestApiSecret::SERVER_KEY] = $this->get(TestApiSecret::class)->ensureExists();
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['charset'] = 'utf8';

        $this->asWebRequest(static function (): void {
            TestContext::applyDatabaseConnectionOverrides();
        });

        $connection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
        self::assertStringEndsWith('db' . self::TEST_ID . '.sqlite', $connection['path']);
        self::assertSame('utf8', $connection['charset']);
    }

    // A project whose credentials come from environment variables has nothing in
    // $GLOBALS yet at this point.
    #[Test]
    public function theConnectionPassedInIsUsedWhenGlobalsCarriesNoneYet(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $_SERVER[TestApiSecret::SERVER_KEY] = $this->get(TestApiSecret::class)->ensureExists();
        $passedIn = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [];

        $this->asWebRequest(static function () use ($passedIn): void {
            TestContext::applyDatabaseConnectionOverrides($passedIn);
        });

        self::assertStringEndsWith(
            'db' . self::TEST_ID . '.sqlite',
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path']
        );
    }

    #[Test]
    public function redirectingWithoutTheSecretCreatesNothing(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        unset($_SERVER[TestApiSecret::SERVER_KEY]);

        $this->asWebRequest(static function (): void {
            TestContext::applyDatabaseConnectionOverrides();
        });

        self::assertFileDoesNotExist(
            Environment::getVarPath() . '/test-databases/db' . self::TEST_ID . '.sqlite'
        );
    }

    // A project that merges the paths itself gets them from here, and a database
    // nothing created would fail every request that follows.
    #[Test]
    public function noConnectionIsNamedWhenNothingWasCreated(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        unset($_SERVER[TestApiSecret::SERVER_KEY]);
        $projectConnection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];

        $overrides = [];
        $this->asWebRequest(static function () use ($projectConnection, &$overrides): void {
            $overrides = TestContext::databaseConnectionOverrides($projectConnection);
        });

        self::assertSame([], $overrides);
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
}
