<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\SeededBackendUser;
use Plan2net\PlaywrightToolkit\Database\SeededSession;

abstract class ServerTestDatabaseDriver implements TestDatabaseDriver
{
    /**
     * @var string
     */
    public const TEMPLATE_DATABASE = 'playwright_db_template';

    protected readonly string $driverName;

    // final, so onTestServiceFor()'s `new static()` cannot meet a child that took
    // different arguments.
    final public function __construct(
        protected readonly string $host,
        protected readonly int $port,
        protected readonly string $user,
        protected readonly string $password,
        protected readonly string $templateDatabase = self::TEMPLATE_DATABASE,
        ?string $driverName = null,
    ) {
        $this->driverName = $driverName ?? $this->engine()->defaultDoctrineDriver();
    }

    #[\Override]
    public function connectionOverrides(string $testId): array
    {
        if ('' === $testId) {
            return [];
        }

        return $this->overridesFor($this->databaseFor($testId));
    }

    #[\Override]
    public function templateConnectionOverrides(): array
    {
        return $this->overridesFor($this->templateDatabase);
    }

    #[\Override]
    public function schemaConnectionOverrides(): array
    {
        return $this->templateConnectionOverrides();
    }

    #[\Override]
    public function templateFingerprint(): ?string
    {
        try {
            $statement = $this->connect($this->templateDatabase)
                ->query('SELECT fingerprint FROM playwright_seed');
        } catch (\PDOException) {
            return null;
        }

        $fingerprint = $statement->fetchColumn();

        return is_string($fingerprint) ? $fingerprint : null;
    }

    #[\Override]
    public function templateExists(): bool
    {
        $statement = $this->admin()->prepare($this->databaseCountQuery());
        $statement->execute([$this->templateDatabase]);

        return (int) $statement->fetchColumn() > 0;
    }

    #[\Override]
    public function createEmptyTemplate(): void
    {
        $admin = $this->admin();
        $this->dropDatabase($admin, $this->templateDatabase);
        $this->createDatabase($admin, $this->templateDatabase);
    }

    #[\Override]
    public function seedTemplate(TemplateSeed $seed): void
    {
        $template = $this->connect($this->templateDatabase);

        $this->applyFixtures($template, $seed->fixtures);

        $statement = $template->prepare($this->seededSessionInsert());
        $statement->execute(
            SeededSession::row($seed->plainSessionId, $seed->sessionUserId)
        );

        $statement = $template->prepare($this->seededBackendUserInsert());
        $statement->execute(SeededBackendUser::row($seed->sessionUserId));
    }

    #[\Override]
    public function finaliseTemplate(string $fingerprint): void
    {
        $template = $this->connect($this->templateDatabase);
        $template->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS playwright_seed (fingerprint %s NOT NULL)',
            $this->fingerprintColumnType()
        ));
        $template->exec('DELETE FROM playwright_seed');

        $statement = $template->prepare('INSERT INTO playwright_seed (fingerprint) VALUES (:fingerprint)');
        $statement->execute(['fingerprint' => $fingerprint]);
    }

    #[\Override]
    public function materialise(string $testId): void
    {
        $database = $this->databaseFor($testId);

        $this->dropDatabase($this->admin(), $database);
        $this->cloneTemplateInto($database);
    }

    #[\Override]
    public function exists(string $testId): bool
    {
        $statement = $this->admin()->prepare($this->databaseCountQuery());
        $statement->execute([$this->databaseFor($testId)]);

        return (int) $statement->fetchColumn() > 0;
    }

    #[\Override]
    public function drop(string $testId): void
    {
        $this->dropDatabase($this->admin(), $this->databaseFor($testId));
    }

    public function dropTemplate(): void
    {
        $this->dropDatabase($this->admin(), $this->templateDatabase);
    }

    #[\Override]
    public function hasSeededSession(
        string $testId,
        string $plainSessionId,
        int $sessionUserId,
    ): bool {
        try {
            $statement = $this->connect($this->databaseFor($testId))->prepare(
                'SELECT count(*) FROM ' . SeededSession::TABLE
                . ' WHERE ses_id = :ses_id AND ses_iplock = :ses_iplock AND ses_userid = :ses_userid'
            );
            $statement->execute(SeededSession::criteria($plainSessionId, $sessionUserId));
        } catch (\PDOException) {
            return false;
        }

        return (int) $statement->fetchColumn() > 0;
    }

    #[\Override]
    public function checkTestDatabase(string $testId): array
    {
        $database = $this->databaseFor($testId);

        try {
            $this->connect($database)->query('SELECT 1');
        } catch (\PDOException $exception) {
            return [
                'ok' => false,
                'detail' => sprintf('Test database %s unreachable: %s', $database, $exception->getMessage()),
            ];
        }

        return ['ok' => true, 'detail' => sprintf('Test database %s is reachable.', $database)];
    }

    /** Children pin their engine with a `private const Engine ENGINE` and delegate here. */
    protected static function onTestServiceFor(Engine $engine, ?string $driverName): static
    {
        $service = TestDatabaseService::fromEnvironment($engine);

        return new static(
            host: $service->host,
            port: $service->port,
            user: $service->user,
            password: $service->password,
            driverName: $driverName,
        );
    }

    /** Null when the engine connects without naming a database. */
    abstract protected function adminDatabase(): ?string;

    abstract protected function dsn(?string $database): string;

    abstract protected function createDatabase(\PDO $admin, string $database): void;

    /** The clone primitive, which only Postgres has as a single statement. */
    abstract protected function cloneTemplateInto(string $database): void;

    /** Re-seeding must not fail on the session row a previous run left. */
    abstract protected function seededSessionInsert(): string;

    abstract protected function seededBackendUserInsert(): string;

    abstract protected function fingerprintColumnType(): string;

    /** Takes the database name as its single positional parameter. */
    abstract protected function databaseCountQuery(): string;

    /**
     * Both engines refuse to drop a database something is still connected to, and
     * a previous worker's connection is enough to block it.
     */
    abstract protected function dropDatabase(\PDO $admin, string $database): void;

    /**
     * @param array<string, string> $fixtures
     */
    protected function applyFixtures(\PDO $template, array $fixtures): void
    {
        foreach ($fixtures as $sql) {
            $template->exec($sql);
        }
    }

    protected function admin(): \PDO
    {
        return $this->connect($this->adminDatabase());
    }

    protected function connect(?string $database): \PDO
    {
        $this->engine()->assertCanProvision();

        $connection = new \PDO($this->dsn($database), $this->user, $this->password);
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }

    protected function databaseFor(string $testId): string
    {
        return DatabaseName::forTestIdChecked($testId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function overridesFor(string $database): array
    {
        return [
            'DB/Connections/Default/driver' => $this->driverName,
            'DB/Connections/Default/dbname' => $database,
            'DB/Connections/Default/host' => $this->host,
            'DB/Connections/Default/port' => $this->port,
            'DB/Connections/Default/user' => $this->user,
            'DB/Connections/Default/password' => $this->password,
        ];
    }
}
