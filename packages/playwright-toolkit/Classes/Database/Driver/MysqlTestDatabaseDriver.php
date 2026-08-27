<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\SeededSession;

// MySQL has no CREATE DATABASE ... TEMPLATE, and CREATE TABLE ... LIKE drops
// foreign keys, so the server's own DDL is copied instead.
final class MysqlTestDatabaseDriver extends ServerTestDatabaseDriver
{
    /**
     * @var Engine
     */
    private const ENGINE = Engine::Mysql;

    #[\Override]
    public function engine(): Engine
    {
        return self::ENGINE;
    }

    public static function onTestService(?string $driverName = null): static
    {
        return self::onTestServiceFor(self::ENGINE, $driverName);
    }

    /**
     * The session table last: readiness is judged by that row, so a clone that
     * dies halfway must not have written it yet.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    public static function copyOrder(array $tables): array
    {
        $others = array_values(array_filter($tables, static fn(string $table): bool => SeededSession::TABLE !== $table));

        return count($others) === count($tables) ? $others : [...$others, SeededSession::TABLE];
    }

    #[\Override]
    protected function dsn(?string $database): string
    {
        $dsn = sprintf('mysql:host=%s;port=%d', $this->host, $this->port);

        return null === $database ? $dsn : $dsn . ';dbname=' . $database;
    }

    #[\Override]
    protected function adminDatabase(): ?string
    {
        return null;
    }

    #[\Override]
    protected function createDatabase(\PDO $admin, string $database): void
    {
        $admin->exec(sprintf(
            'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database
        ));
    }

    #[\Override]
    protected function cloneTemplateInto(string $database): void
    {
        $this->createDatabase($this->admin(), $database);

        $template = $this->connect($this->templateDatabase);
        $target = $this->connect($database);
        $target->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::copyOrder($this->templateTables($template)) as $table) {
            $target->exec($this->createTableStatement($template, $table));

            $columns = $this->copyableColumns($template, $table);
            if ([] === $columns) {
                continue;
            }

            $columnList = implode(', ', array_map(static fn(string $column): string => "`$column`", $columns));
            $target->exec(sprintf(
                'INSERT INTO `%s`.`%s` (%s) SELECT %s FROM `%s`.`%s`',
                $database,
                $table,
                $columnList,
                $columnList,
                $this->templateDatabase,
                $table
            ));
        }

        // After the tables, since a view selects from them.
        foreach ($this->templateViews($template) as $view) {
            $target->exec($this->createViewStatement($template, $view, $database));
        }

        $target->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    #[\Override]
    protected function applyFixtures(\PDO $template, array $fixtures): void
    {
        $template->exec('SET FOREIGN_KEY_CHECKS = 0');
        parent::applyFixtures($template, $fixtures);
        $template->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    #[\Override]
    protected function seededSessionInsert(): string
    {
        return 'INSERT IGNORE INTO be_sessions (ses_id, ses_iplock, ses_userid, ses_tstamp, ses_data)
             VALUES (:ses_id, :ses_iplock, :ses_userid, :ses_tstamp, :ses_data)';
    }

    #[\Override]
    protected function seededBackendUserInsert(): string
    {
        return 'INSERT IGNORE INTO be_users (uid, pid, username, password, admin, disable, deleted, tstamp, crdate)
             VALUES (:uid, :pid, :username, :password, :admin, :disable, :deleted, :tstamp, :crdate)';
    }

    #[\Override]
    protected function fingerprintColumnType(): string
    {
        return 'varchar(190)';
    }

    #[\Override]
    protected function databaseCountQuery(): string
    {
        return 'SELECT count(*) FROM information_schema.schemata WHERE schema_name = ?';
    }

    #[\Override]
    protected function dropDatabase(\PDO $admin, string $database): void
    {
        $statement = $admin->prepare(
            'SELECT id FROM information_schema.processlist WHERE db = ? AND id <> CONNECTION_ID()'
        );
        $statement->execute([$database]);

        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $processId) {
            try {
                $admin->exec(sprintf('KILL %d', (int) $processId));
            } catch (\PDOException) {
                // the process ended on its own between the query and the kill
            }
        }

        $admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
    }

    /**
     * @return list<string>
     */
    private function templateTables(\PDO $template): array
    {
        $statement = $template->prepare(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name"
        );
        $statement->execute([$this->templateDatabase]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function createTableStatement(\PDO $template, string $table): string
    {
        $row = $template->query(sprintf('SHOW CREATE TABLE `%s`', $table))->fetch(\PDO::FETCH_NUM);

        return (string) $row[1];
    }

    /**
     * @return list<string>
     */
    private function templateViews(\PDO $template): array
    {
        $statement = $template->prepare(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = ? AND table_type = 'VIEW' ORDER BY table_name"
        );
        $statement->execute([$this->templateDatabase]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * SHOW CREATE VIEW names the template in its FROM clauses. DEFINER and SQL
     * SECURITY are dropped, since whoever runs the tests may not name the original.
     */
    private function createViewStatement(\PDO $template, string $view, string $database): string
    {
        $row = $template->query(sprintf('SHOW CREATE VIEW `%s`', $view))->fetch(\PDO::FETCH_NUM);
        $statement = (string) $row[1];

        $statement = preg_replace(
            '/^CREATE\s+(?:ALGORITHM=\S+\s+)?(?:DEFINER=\S+\s+)?(?:SQL SECURITY \w+\s+)?VIEW/i',
            'CREATE VIEW',
            $statement,
            1
        ) ?? $statement;

        return str_replace(
            sprintf('`%s`.', $this->templateDatabase),
            sprintf('`%s`.', $database),
            $statement
        );
    }

    /**
     * `SELECT *` is not a faithful copy: it omits invisible columns, losing their
     * data, and includes generated ones, which reject an explicit value.
     *
     * @return list<string>
     */
    private function copyableColumns(\PDO $template, string $table): array
    {
        $statement = $template->prepare(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
               AND (generation_expression IS NULL OR generation_expression = '')
             ORDER BY ordinal_position"
        );
        $statement->execute([$this->templateDatabase, $table]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
