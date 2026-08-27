<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseInitializerSeedingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/pw-seeding-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    // The later fixtures depend on the earlier ones, so a wrong order fails
    // outright rather than producing a differently ordered table.
    #[Test]
    public function appliesEveryManifestFileInTheGivenOrder(): void
    {
        $driver = new SqliteTestDatabaseDriver($this->directory);
        $driver->createEmptyTemplate();

        $driver->seedTemplate(new TemplateSeed(
            fixtures: [
                'first.sql' => 'CREATE TABLE seed_order (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT);',
                'second.sql' => "INSERT INTO seed_order (label) VALUES ('second');",
                'third.sql' => "INSERT INTO seed_order (label) VALUES ('third');",
            ],
            plainSessionId: 'playwright_test_session',
            sessionUserId: 1,
        ));

        $connection = new \PDO('sqlite:' . $this->directory . '/playwright_db_template.sqlite');
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $rows = $connection->query('SELECT label FROM seed_order ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['second', 'third'], $rows);
    }
}
