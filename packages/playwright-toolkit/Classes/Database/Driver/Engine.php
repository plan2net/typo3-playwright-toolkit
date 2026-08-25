<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

/** The backed value is the wire string the HTTP endpoints report. */
enum Engine: string
{
    case Postgres = 'postgres';
    case Mysql = 'mysql';
    case Sqlite = 'sqlite';

    public static function fromDoctrineDriver(string $driver): self
    {
        return match ($driver) {
            'pdo_pgsql', 'pgsql' => self::Postgres,
            'mysqli', 'pdo_mysql' => self::Mysql,
            'pdo_sqlite', 'sqlite3' => self::Sqlite,
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported database driver "%s". Supported: pdo_pgsql, pgsql, mysqli, pdo_mysql, pdo_sqlite, sqlite3.',
                $driver
            )),
        };
    }

    /** The driver a project on this engine uses unless it names another. */
    public function defaultDoctrineDriver(): string
    {
        return match ($this) {
            self::Postgres => 'pdo_pgsql',
            self::Mysql => 'mysqli',
            self::Sqlite => 'pdo_sqlite',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Postgres => 5432,
            self::Mysql => 3306,
            self::Sqlite => throw new \LogicException('A sqlite test database is a file and has no port.'),
        };
    }

    public function pdoDriver(): string
    {
        return match ($this) {
            self::Postgres => 'pgsql',
            self::Mysql => 'mysql',
            self::Sqlite => 'sqlite',
        };
    }

    /**
     * Provisioning goes through PDO whatever driver the project queries with, so
     * mysqli or pgsql alone is not enough to create a test database.
     */
    public function assertCanProvision(): void
    {
        if (in_array($this->pdoDriver(), \PDO::getAvailableDrivers(), true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Provisioning a %s test database needs the PHP extension "pdo_%s", which is not installed.',
            $this->value,
            $this->pdoDriver()
        ));
    }
}
