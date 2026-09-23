<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Compatibility\RetryingProcessingFolderStorage;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class TestContextTest extends TestCase
{
    private ?string $originalTestId = null;

    /** @var array<string, mixed>|null */
    private ?array $originalEnvironment = null;

    protected function setUp(): void
    {
        $this->originalTestId = $_SERVER[TestContext::TEST_ID_SERVER_KEY] ?? null;
        $this->originalEnvironment = self::captureEnvironment();

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/app',
            '/app/public',
            '/app/var',
            '/app/config',
            '/app/public/index.php',
            'UNIX',
        );
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[TestContext::TEST_ID_COOKIE]);

        if (null === $this->originalTestId) {
            unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        } else {
            $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $this->originalTestId;
        }

        // Reading the getters here instead would write setUp's own fake paths back.
        if (null !== $this->originalEnvironment) {
            Environment::initialize(
                $this->originalEnvironment['context'],
                $this->originalEnvironment['cli'],
                $this->originalEnvironment['composerMode'],
                $this->originalEnvironment['projectPath'],
                $this->originalEnvironment['publicPath'],
                $this->originalEnvironment['varPath'],
                $this->originalEnvironment['configPath'],
                $this->originalEnvironment['currentScript'],
                $this->originalEnvironment['os'],
            );
        }

        parent::tearDown();
    }

    #[Test]
    public function applyingWithoutATestIdMovesOffTheProjectDatabase(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_pgsql',
            'dbname' => 'the_real_database',
        ];

        TestContext::configureCurrentRequest();

        self::assertSame('db', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname']);
        self::assertSame('db-test', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host']);
    }

    // TYPO3 caches the resolved site configuration, %env() placeholders and all,
    // under a key that carries no context, in a directory that carries none either.
    // Development and Testing share one checkout here, so whichever warms it first
    // would decide the other's environment.
    #[Test]
    public function theTestingContextCachesItsFileEntriesApart(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = [
            'core' => ['backend' => SimpleFileBackend::class],
            'fluid_template' => ['backend' => FileBackend::class],
            'pages' => ['backend' => Typo3DatabaseBackend::class],
        ];

        $settings = TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql']);

        self::assertSame(
            Environment::getVarPath() . '/cache-testing/',
            $settings['SYS/caching/cacheConfigurations/core/options/cacheDirectory'] ?? null
        );
        self::assertSame(
            Environment::getVarPath() . '/cache-testing/',
            $settings['SYS/caching/cacheConfigurations/fluid_template/options/cacheDirectory'] ?? null
        );
        self::assertArrayNotHasKey('SYS/caching/cacheConfigurations/pages/options/cacheDirectory', $settings);
    }

    // A backend throws on an option it has no setter for, so a cache the project
    // turned off, or moved to redis, would fail every request of this context.
    #[Test]
    public function aBackendThatTakesNoDirectoryIsLeftAlone(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = [
            'core' => ['backend' => NullBackend::class],
            // An absent backend is Typo3DatabaseBackend.
            'l10n' => ['options' => []],
        ];

        $settings = TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql']);

        self::assertArrayNotHasKey('SYS/caching/cacheConfigurations/core/options/cacheDirectory', $settings);
        self::assertArrayNotHasKey('SYS/caching/cacheConfigurations/l10n/options/cacheDirectory', $settings);
    }

    #[Test]
    public function aCacheDirectoryTheProjectSetIsLeftAlone(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = [
            'core' => [
                'backend' => SimpleFileBackend::class,
                'options' => ['cacheDirectory' => '/somewhere/else/'],
            ],
        ];

        $settings = TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql']);

        self::assertArrayNotHasKey('SYS/caching/cacheConfigurations/core/options/cacheDirectory', $settings);
    }

    #[Test]
    public function resolvedSettingsCarryErrorCaptureForAProjectThatAppliesThemItself(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];

        $settings = TestContext::resolveCurrentRequestSettings([
            'driver' => 'pdo_pgsql',
            'dbname' => 'the_real_database',
        ]);

        self::assertArrayHasKey(
            'LOG/writerConfiguration/' . LogLevel::ERROR . '/' . DatabaseWriter::class,
            $settings
        );
    }

    #[Test]
    public function replacesTheResourceStorageWhereCoreFailsAFolderRace(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            self::markTestSkipped('This core takes the folder itself.');
        }

        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $settings = TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql']);

        self::assertSame(
            RetryingProcessingFolderStorage::class,
            $settings['SYS/Objects/' . ResourceStorage::class . '/className'] ?? null
        );
    }

    #[Test]
    public function registersErrorCaptureForTheRequest(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];

        TestContext::configureCurrentRequest();

        self::assertArrayHasKey(
            DatabaseWriter::class,
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][LogLevel::ERROR]
        );
    }

    #[Test]
    public function anEmptyTestIdStillMovesTheConnectionToTheTestService(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $settings = TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql']);

        self::assertSame('db-test', $settings['DB/Connections/Default/host']);
        self::assertSame('db', $settings['DB/Connections/Default/dbname']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTestIds(): array
    {
        return [
            'not the contract pattern' => ['not-a-valid-id'],
            'sql metacharacters' => ['";DROP DATABASE x;--'],
            'path traversal' => ['../../etc/passwd'],
            'too short' => ['ABCD1234'],
            'lowercase' => ['abcd1234efgh5678'],
        ];
    }

    /**
     * A header the toolkit did not send must not name the database. It lands on the
     * fixed base one, and DatabaseName::assertProvisionable() is still there if one
     * ever gets that far.
     */
    #[Test]
    #[DataProvider('malformedTestIds')]
    public function usesTheBaseDatabaseForAMalformedTestId(string $testId): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $testId;

        self::assertSame('', TestContext::testId());
        self::assertSame(
            'db',
            TestContext::resolveCurrentRequestSettings(['driver' => 'pdo_pgsql'])['DB/Connections/Default/dbname']
        );
    }

    #[Test]
    #[DataProvider('malformedTestIds')]
    public function reportsAMalformedTestIdSoItCanBeLogged(string $testId): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $testId;

        self::assertSame($testId, TestContext::malformedTestId());
    }

    #[Test]
    public function reportsNoMalformedTestIdForAWellFormedOne(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        self::assertNull(TestContext::malformedTestId());
    }

    /**
     * A browser cannot send the header, so the inspect link leaves a cookie behind
     * instead. Test runs always send the header, so they never reach this.
     */
    #[Test]
    public function fallsBackToTheInspectCookieWhenNoHeaderWasSent(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $_COOKIE[TestContext::TEST_ID_COOKIE] = 'ABCD1234EFGH5678';

        self::assertSame('ABCD1234EFGH5678', TestContext::testId());
    }

    #[Test]
    public function prefersTheHeaderOverTheCookie(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'AAAA1111AAAA1111';
        $_COOKIE[TestContext::TEST_ID_COOKIE] = 'BBBB2222BBBB2222';

        self::assertSame('AAAA1111AAAA1111', TestContext::testId());
    }

    #[Test]
    public function ignoresAMalformedCookieTheSameWayAsAMalformedHeader(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $_COOKIE[TestContext::TEST_ID_COOKIE] = '../../etc/passwd';

        self::assertSame('', TestContext::testId());
    }

    #[Test]
    public function reportsNoMalformedTestIdWhenTheHeaderIsAbsent(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        self::assertNull(TestContext::malformedTestId());
    }

    /**
     * @return array<string, mixed>|null null when Environment was never initialized
     */
    private static function captureEnvironment(): ?array
    {
        try {
            return [
                'context' => Environment::getContext(),
                'cli' => Environment::isCli(),
                'composerMode' => Environment::isComposerMode(),
                'projectPath' => Environment::getProjectPath(),
                'publicPath' => Environment::getPublicPath(),
                'varPath' => Environment::getVarPath(),
                'configPath' => Environment::getConfigPath(),
                'currentScript' => Environment::getCurrentScript(),
                'os' => Environment::isWindows() ? 'WINDOWS' : 'UNIX',
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
