<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Driver;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;

/**
 * What every engine's setup cache must do identically, so a delta behaves the same
 * whether the project runs on postgres, mysql or sqlite.
 */
trait SetupCacheDeltaTests
{
    #[Test]
    public function findsOnlyTheTablesWrittenToSinceTheClone(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        $template->exec('CREATE TABLE tx_probe_untouched (uid integer PRIMARY KEY, label varchar(255))');
        $template->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the template')");
        $template->exec("INSERT INTO tx_probe_untouched (uid, label) VALUES (1, 'from the template')");
        unset($template);

        $driver->materialise(self::TEST_ID);
        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (uid, title) VALUES (2, 'from the setup')");

        self::assertSame(['tx_probe_written'], $driver->changedTables(self::TEST_ID));
    }

    #[Test]
    public function ignoresTheTablesADeltaMustNeverCarry(): void
    {
        $excluded = ['cache_pages', 'cf_something', 'sys_file_processedfile', 'sys_log', 'tx_probe_written'];

        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        foreach ($excluded as $table) {
            $template->exec(sprintf('CREATE TABLE %s (uid integer PRIMARY KEY)', $table));
        }
        unset($template);

        $driver->materialise(self::TEST_ID);
        $test = $this->openTestDatabase();
        foreach ($excluded as $table) {
            $test->exec(sprintf('INSERT INTO %s (uid) VALUES (1)', $table));
        }

        self::assertSame(['tx_probe_written'], $driver->changedTables(self::TEST_ID));
    }

    #[Test]
    public function dumpsAndHashesTheSameSnapshot(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        unset($template);

        $driver->materialise(self::TEST_ID);
        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the setup')");

        $delta = $driver->dumpWithHashes(self::TEST_ID, ['tx_probe_written']);

        self::assertSame(['tx_probe_written'], array_keys($delta['hashes']));
        self::assertNotSame('', $delta['hashes']['tx_probe_written']);

        $driver->materialise(self::TEST_ID);

        self::assertTrue($driver->applyDelta(self::TEST_ID, $delta['sql'], $delta['hashes']));
    }

    #[Test]
    public function replaysADumpedTableIntoAFreshClone(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        $template->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the template')");
        unset($template);

        $driver->materialise(self::TEST_ID);
        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (uid, title) VALUES (2, 'from the setup')");
        $delta = $driver->dumpWithHashes(self::TEST_ID, ['tx_probe_written']);

        $driver->materialise(self::TEST_ID);
        $driver->applyDelta(self::TEST_ID, $delta['sql']);

        self::assertSame(
            ['from the setup', 'from the template'],
            $this->openTestDatabase()
                ->query('SELECT title FROM tx_probe_written ORDER BY title')
                ->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    #[Test]
    public function leavesTheKeySequenceBehindTheRowsItRestored(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec(sprintf('CREATE TABLE tx_probe_written (%s, title varchar(255))', static::keyColumn()));
        unset($template);

        $driver->materialise(self::TEST_ID);
        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the setup')");
        $delta = $driver->dumpWithHashes(self::TEST_ID, ['tx_probe_written']);
        $driver->materialise(self::TEST_ID);

        $driver->applyDelta(self::TEST_ID, $delta['sql']);

        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (title) VALUES ('written after the restore')");

        self::assertSame(
            2,
            (int) $this->openTestDatabase()->query('SELECT count(*) FROM tx_probe_written')->fetchColumn()
        );
    }

    #[Test]
    public function replaysABinaryColumnUnchanged(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec(sprintf(
            'CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, payload %s)',
            static::binaryColumnType()
        ));
        unset($template);

        $driver->materialise(self::TEST_ID);
        $write = $this->openTestDatabase()->prepare(
            'INSERT INTO tx_probe_written (uid, payload) VALUES (1, :payload)'
        );
        $write->bindValue('payload', "a:1:{s:5:\"title\";s:3:\"hi\x00\";}", \PDO::PARAM_LOB);
        $write->execute();

        $delta = $driver->dumpWithHashes(self::TEST_ID, ['tx_probe_written']);
        $driver->materialise(self::TEST_ID);

        self::assertTrue($driver->applyDelta(self::TEST_ID, $delta['sql'], $delta['hashes']));
    }

    #[Test]
    public function commitsADeltaThatProducesThePromisedHash(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        unset($template);

        $driver->materialise(self::TEST_ID);
        $this->openTestDatabase()->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the setup')");
        $delta = $driver->dumpWithHashes(self::TEST_ID, ['tx_probe_written']);

        $driver->materialise(self::TEST_ID);

        self::assertTrue($driver->applyDelta(self::TEST_ID, $delta['sql'], $delta['hashes']));
        self::assertSame(
            ['from the setup'],
            $this->openTestDatabase()->query('SELECT title FROM tx_probe_written')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    #[Test]
    public function rollsBackADeltaThatDoesNotProduceThePromisedHash(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        $template->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the template')");
        unset($template);

        $driver->materialise(self::TEST_ID);

        $applied = $driver->applyDelta(
            self::TEST_ID,
            "INSERT INTO tx_probe_written (uid, title) VALUES (2, 'from a delta that lies');",
            ['tx_probe_written' => 'not-the-hash-this-produces']
        );

        self::assertFalse($applied);
        self::assertSame(
            ['from the template'],
            $this->openTestDatabase()->query('SELECT title FROM tx_probe_written')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    #[Test]
    public function leavesThePlainCloneBehindWhenADeltaFailsHalfway(): void
    {
        $driver = $this->prepareTemplate();
        $template = $this->openTemplate();
        $template->exec('CREATE TABLE tx_probe_written (uid integer PRIMARY KEY, title varchar(255))');
        $template->exec("INSERT INTO tx_probe_written (uid, title) VALUES (1, 'from the template')");
        unset($template);

        $driver->materialise(self::TEST_ID);

        try {
            $driver->applyDelta(self::TEST_ID, implode("\n", [
                'DELETE FROM tx_probe_written;',
                "INSERT INTO tx_probe_written (uid, title) VALUES (2, 'half applied');",
                'INSERT INTO tx_probe_no_such_table (uid) VALUES (3);',
            ]));
            self::fail('Expected the broken delta to be refused');
        } catch (\PDOException) {
        }

        self::assertSame(
            ['from the template'],
            $this->openTestDatabase()->query('SELECT title FROM tx_probe_written')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    abstract protected static function binaryColumnType(): string;

    abstract protected static function keyColumn(): string;

    abstract protected function prepareTemplate(int $userId = 1, string $fingerprint = 'abc'): TestDatabaseDriver;

    abstract protected function openTemplate(): \PDO;

    abstract protected function openTestDatabase(): \PDO;
}
