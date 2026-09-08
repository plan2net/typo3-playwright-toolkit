<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Media;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\PostgresTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\Media\MediaManifest;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaSeederPostgresTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const PNG_4X3 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4b'
        . 'AAAAFElEQVQImWMU6YligAEmBiSAwgEAJHgBAMXOa18AAAAASUVORK5CYII=';

    /**
     * @var int
     */
    private const FIXTURE_FILE_UID = 4200;

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    /** @var array<string, mixed> */
    private array $originalConnections = [];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private bool $serverIsReachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'];

        foreach ([
            TestDatabaseService::HOST_VARIABLE => self::host(),
            TestDatabaseService::USER_VARIABLE => self::user(),
            TestDatabaseService::PASSWORD_VARIABLE => self::password(),
        ] as $variable => $value) {
            $this->originalEnvironment[$variable] = getenv($variable);
            putenv($variable . '=' . $value);
        }

        try {
            new \PDO(
                sprintf('pgsql:host=%s;port=5432;dbname=postgres', self::host()),
                self::user(),
                self::password()
            );
            $this->serverIsReachable = true;
        } catch (\PDOException $exception) {
            self::markTestSkipped('No postgres at ' . self::host() . ': ' . $exception->getMessage());
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'the-encryption-key';

        $fixtures = Environment::getProjectPath() . '/playwright-fixtures-postgres-media';
        GeneralUtility::mkdir_deep($fixtures);
        file_put_contents(
            $fixtures . '/files.sql',
            sprintf(
                'INSERT INTO sys_file (uid, pid, storage, identifier, identifier_hash, folder_hash, extension,'
                . " mime_type, name, sha1, size) VALUES (%d, 0, 0, '/already-there.png', 'a', 'b', 'png',"
                . " 'image/png', 'already-there.png', 'c', 1);",
                self::FIXTURE_FILE_UID
            )
        );

        $media = Environment::getProjectPath() . '/postgres-media';
        GeneralUtility::rmdir($media, true);
        GeneralUtility::mkdir_deep($media);
        file_put_contents($media . '/hero.png', (string) base64_decode(self::PNG_4X3, true));

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'playwright-fixtures-postgres-media',
            'fixtureManifest' => 'files.sql',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '1',
            'mediaPath' => 'postgres-media',
        ];

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_pgsql',
            'host' => self::host(),
            'port' => 5432,
            'user' => self::user(),
            'password' => self::password(),
            'dbname' => 'db',
            'charset' => 'utf8',
        ];
    }

    protected function tearDown(): void
    {
        if ($this->serverIsReachable) {
            PostgresTestDatabaseDriver::onTestService()->dropTemplate();
        }

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = $this->originalConnections;

        foreach ($this->originalEnvironment as $variable => $value) {
            is_string($value) ? putenv($variable . '=' . $value) : putenv($variable);
        }

        parent::tearDown();
    }

    #[Test]
    public function indexesMediaPastAUidAFixtureNamed(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $file = $this->get(MediaManifest::class)->file();
        self::assertFileExists($file);

        /** @var array<string, int> $manifest */
        $manifest = (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('hero.png', $manifest);
        self::assertGreaterThan(
            self::FIXTURE_FILE_UID,
            $manifest['hero.png'],
            'the sequence was not advanced past the uid the fixture named'
        );
    }

    private static function host(): string
    {
        return self::environment('PW_TEST_POSTGRES_HOST', 'db-test');
    }

    private static function user(): string
    {
        return self::environment('PW_TEST_POSTGRES_USER', 'db');
    }

    private static function password(): string
    {
        return self::environment('PW_TEST_POSTGRES_PASSWORD', 'db');
    }

    private static function environment(string $variable, string $fallback): string
    {
        $value = getenv($variable);

        return \is_string($value) && '' !== $value ? $value : $fallback;
    }
}
