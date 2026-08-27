<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

final class PostgresTestDatabaseDriver extends ServerTestDatabaseDriver
{
    /**
     * @var Engine
     */
    private const ENGINE = Engine::Postgres;

    /**
     * @var string
     */
    private const ADMIN_DATABASE = 'postgres';

    #[\Override]
    public function engine(): Engine
    {
        return self::ENGINE;
    }

    public static function onTestService(?string $driverName = null): static
    {
        return self::onTestServiceFor(self::ENGINE, $driverName);
    }

    // The WAL_LOG default of postgres 15+ writes every copied block into the WAL
    // too, which the fsync-off test service gains nothing from.
    public static function cloneStatement(string $database, string $template, string $serverVersion): string
    {
        $statement = sprintf('CREATE DATABASE "%s" TEMPLATE "%s"', $database, $template);

        return self::supportsCloneStrategy($serverVersion)
            ? $statement . ' STRATEGY = FILE_COPY'
            : $statement;
    }

    /**
     * The admin database, not the template: CREATE DATABASE ... TEMPLATE fails while
     * another session holds the template open, and resolving a schema holds one.
     */
    #[\Override]
    public function schemaConnectionOverrides(): array
    {
        return $this->overridesFor(self::ADMIN_DATABASE);
    }

    #[\Override]
    protected function dsn(?string $database): string
    {
        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $database ?? self::ADMIN_DATABASE);
    }

    #[\Override]
    protected function adminDatabase(): string
    {
        return self::ADMIN_DATABASE;
    }

    #[\Override]
    protected function createDatabase(\PDO $admin, string $database): void
    {
        // C collation keeps sort order identical whatever locale the host runs.
        $admin->exec(sprintf(
            "CREATE DATABASE \"%s\" WITH ENCODING 'UTF8' LC_COLLATE='C' LC_CTYPE='C' TEMPLATE template0",
            $database
        ));
    }

    #[\Override]
    protected function cloneTemplateInto(string $database): void
    {
        $admin = $this->admin();

        $admin->exec(self::cloneStatement(
            $database,
            $this->templateDatabase,
            (string) $admin->getAttribute(\PDO::ATTR_SERVER_VERSION)
        ));
    }

    /**
     * A fixture that names its own uids leaves the sequences at the start, so the
     * first row TYPO3 writes would reuse one. Only postgres needs this.
     */
    #[\Override]
    protected function applyFixtures(\PDO $template, array $fixtures): void
    {
        parent::applyFixtures($template, $fixtures);

        $template->exec(<<<'SQL'
            DO $$
            DECLARE
                sequence record;
                highest bigint;
            BEGIN
                FOR sequence IN
                    SELECT table_name, column_name,
                           pg_get_serial_sequence(quote_ident(table_name), column_name) AS name
                    FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND pg_get_serial_sequence(quote_ident(table_name), column_name) IS NOT NULL
                LOOP
                    EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I', sequence.column_name, sequence.table_name)
                        INTO highest;
                    -- is_called false on an empty table, so the first row still gets 1.
                    PERFORM setval(sequence.name, GREATEST(highest, 1), highest > 0);
                END LOOP;
            END $$;
            SQL);
    }

    #[\Override]
    protected function seededSessionInsert(): string
    {
        return 'INSERT INTO be_sessions (ses_id, ses_iplock, ses_userid, ses_tstamp, ses_data)
             VALUES (:ses_id, :ses_iplock, :ses_userid, :ses_tstamp, :ses_data)
             ON CONFLICT (ses_id) DO NOTHING';
    }

    #[\Override]
    protected function seededBackendUserInsert(): string
    {
        return 'INSERT INTO be_users (uid, pid, username, password, admin, disable, deleted, tstamp, crdate)
             VALUES (:uid, :pid, :username, :password, :admin, :disable, :deleted, :tstamp, :crdate)
             ON CONFLICT (uid) DO NOTHING';
    }

    #[\Override]
    protected function fingerprintColumnType(): string
    {
        return 'text';
    }

    #[\Override]
    protected function databaseCountQuery(): string
    {
        return 'SELECT count(*) FROM pg_database WHERE datname = ?';
    }

    #[\Override]
    protected function dropDatabase(\PDO $admin, string $database): void
    {
        // One statement, so no session can connect between terminating the others
        // and the drop itself.
        $admin->exec(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $database));
    }

    private static function supportsCloneStrategy(string $serverVersion): bool
    {
        return 1 === preg_match('/^(\d+)/', $serverVersion, $major) && (int) $major[1] >= 15;
    }
}
