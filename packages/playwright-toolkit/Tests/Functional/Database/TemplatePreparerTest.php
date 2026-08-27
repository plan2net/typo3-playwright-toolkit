<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\SeededSession;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TemplatePreparerTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const ENCRYPTION_KEY = 'the-encryption-key';
    /**
     * @var string
     */
    private const SESSION_ID = 'playwright_test_session';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;

        $fixturesPath = Environment::getProjectPath() . '/playwright-fixtures';
        if (!is_dir($fixturesPath)) {
            mkdir($fixturesPath, 0777, true);
        }
        file_put_contents(
            $fixturesPath . '/pages.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (99, 0, 'Fixture root');"
        );

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'playwright-fixtures',
            'fixtureManifest' => 'pages.sql',
            'preseededSessionId' => self::SESSION_ID,
            'sessionUserId' => '1',
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templateDirectory() . '/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function buildsATemplateCarryingTheSchemaTheMigratorKnows(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        self::assertContains('pages', $this->templateTables());
        self::assertContains('be_sessions', $this->templateTables());
    }

    #[Test]
    public function theTemplateCarriesTheFixtures(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $count = $this->template()
            ->query('SELECT count(*) FROM pages WHERE uid = 99')
            ->fetchColumn();

        self::assertSame('1', (string) $count);
    }

    #[Test]
    public function theTemplateCarriesTheSeededSession(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $statement = $this->template()->prepare('SELECT count(*) FROM be_sessions WHERE ses_id = ?');
        $statement->execute([SeededSession::hashedSessionId(self::SESSION_ID)]);

        self::assertSame('1', (string) $statement->fetchColumn());
    }

    /**
     * Without it the session authenticates against nothing and every backend
     * route answers with a redirect to the login form.
     */
    #[Test]
    public function theTemplateCarriesTheBackendUserTheSessionBelongsTo(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $statement = $this->template()->prepare(
            'SELECT username, admin, disable, deleted FROM be_users WHERE uid = ?'
        );
        $statement->execute([1]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($user, 'the seeded session points at be_users uid 1');
        self::assertSame(1, (int) $user['admin']);
        self::assertSame(0, (int) $user['disable']);
        self::assertSame(0, (int) $user['deleted']);
    }

    #[Test]
    public function aFinishedTemplateCarriesTheFingerprintThatWasReturned(): void
    {
        $fingerprint = $this->get(TemplatePreparer::class)->prepare()['fingerprint'];

        self::assertSame($fingerprint, $this->driver()->templateFingerprint());
    }

    #[Test]
    public function aTemplateWhoseSeedingFailedCarriesNoFingerprint(): void
    {
        $this->breakTheFixtures();

        try {
            $this->get(TemplatePreparer::class)->prepare();
            self::fail('Expected the broken fixture to abort preparation.');
        } catch (\PDOException) {
            // the failure is the point
        }

        self::assertContains('pages', $this->templateTables(), 'migration should have run first');
        self::assertNull($this->driver()->templateFingerprint());
    }

    #[Test]
    public function itLeavesTheDefaultConnectionAsItFoundIt(): void
    {
        $before = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];

        $this->get(TemplatePreparer::class)->prepare();

        self::assertSame($before, $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']);
    }

    #[Test]
    public function preparingTwiceLeavesOneSeededSessionNotTwo(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();
        // Forced: an unforced second call would skip and pin nothing.
        $preparer->prepare(force: true);

        $count = $this->template()->query('SELECT count(*) FROM be_sessions')->fetchColumn();

        self::assertSame('1', (string) $count);
    }

    #[Test]
    public function aChangedFixtureRebuildsTheTemplate(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();

        file_put_contents(
            Environment::getProjectPath() . '/playwright-fixtures/pages.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (99, 0, 'Changed fixture root');"
        );
        $preparer->prepare();

        $title = $this->template()->query('SELECT title FROM pages WHERE uid = 99')->fetchColumn();

        self::assertSame('Changed fixture root', $title);
    }

    #[Test]
    public function forceRebuildsATemplateWhoseFingerprintIsCurrent(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();
        $this->template()->exec("INSERT INTO pages (uid, pid, title) VALUES (100, 0, 'manual corruption')");

        $preparer->prepare(force: true);

        $count = $this->template()->query('SELECT count(*) FROM pages WHERE uid = 100')->fetchColumn();

        self::assertSame('0', (string) $count);
    }

    #[Test]
    public function aSecondPrepareWithUnchangedSourcesLeavesTheTemplateUntouched(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();
        $this->template()->exec("INSERT INTO pages (uid, pid, title) VALUES (100, 0, 'left by the first build')");

        $preparer->prepare();

        $count = $this->template()->query('SELECT count(*) FROM pages WHERE uid = 100')->fetchColumn();

        self::assertSame('1', (string) $count);
    }

    #[Test]
    public function theSameSourcesProduceTheSameFingerprint(): void
    {
        $preparer = $this->get(TemplatePreparer::class);

        self::assertSame($preparer->prepare()['fingerprint'], $preparer->prepare()['fingerprint']);
    }

    private function breakTheFixtures(): void
    {
        file_put_contents(
            Environment::getProjectPath() . '/playwright-fixtures/pages.sql',
            'INSERT INTO a_table_the_schema_does_not_have (uid) VALUES (1);'
        );
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver($this->templateDirectory());
    }

    private function templateDirectory(): string
    {
        return Environment::getVarPath() . '/test-databases';
    }

    private function template(): \PDO
    {
        $connection = new \PDO('sqlite:' . $this->templateDirectory() . '/playwright_db_template.sqlite');
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function templateTables(): array
    {
        $names = $this->template()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $names));
    }
}
