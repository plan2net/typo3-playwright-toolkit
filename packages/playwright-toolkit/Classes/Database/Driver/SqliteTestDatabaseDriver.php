<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\SeededBackendUser;
use Plan2net\PlaywrightToolkit\Database\SeededSession;
use TYPO3\CMS\Core\Core\Environment;

final class SqliteTestDatabaseDriver implements TestDatabaseDriver
{
    /**
     * @var string
     */
    private const TEMPLATE_NAME = 'playwright_db_template';

    public function __construct(
        private readonly string $directory,
        private readonly string $driverName = 'pdo_sqlite',
    ) {
    }

    public static function inVarPath(string $driverName = 'pdo_sqlite'): self
    {
        return new self(Environment::getVarPath() . '/test-databases', $driverName);
    }

    #[\Override]
    public function engine(): Engine
    {
        return Engine::Sqlite;
    }

    #[\Override]
    public function connectionOverrides(string $testId): array
    {
        if ('' === $testId) {
            return [];
        }

        return [
            'DB/Connections/Default/driver' => $this->driverName,
            'DB/Connections/Default/path' => $this->fileFor($testId),
        ];
    }

    #[\Override]
    public function templateConnectionOverrides(): array
    {
        return [
            'DB/Connections/Default/driver' => $this->driverName,
            'DB/Connections/Default/path' => $this->templateFile(),
        ];
    }

    #[\Override]
    public function schemaConnectionOverrides(): array
    {
        return $this->templateConnectionOverrides();
    }

    #[\Override]
    public function templateFingerprint(): ?string
    {
        if (!is_file($this->templateFile())) {
            return null;
        }

        $connection = $this->connect($this->templateFile());

        try {
            $statement = $connection->query('SELECT fingerprint FROM playwright_seed');
        } catch (\PDOException) {
            return null;
        }

        $fingerprint = $statement->fetchColumn();

        return is_string($fingerprint) ? $fingerprint : null;
    }

    #[\Override]
    public function templateExists(): bool
    {
        return is_file($this->templateFile());
    }

    #[\Override]
    public function createEmptyTemplate(): void
    {
        $this->ensureDirectory();
        $this->remove($this->templateFile(), 'template');
        $this->connect($this->templateFile());
    }

    #[\Override]
    public function seedTemplate(TemplateSeed $seed): void
    {
        $connection = $this->connect($this->templateFile());

        foreach ($seed->fixtures as $sql) {
            $connection->exec($sql);
        }

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS be_sessions (
                ses_id TEXT PRIMARY KEY,
                ses_iplock TEXT,
                ses_userid INTEGER,
                ses_tstamp INTEGER,
                ses_data TEXT
            )'
        );

        $statement = $connection->prepare(
            'INSERT INTO be_sessions (ses_id, ses_iplock, ses_userid, ses_tstamp, ses_data)
             VALUES (:ses_id, :ses_iplock, :ses_userid, :ses_tstamp, :ses_data)'
        );
        $statement->execute(
            SeededSession::row($seed->plainSessionId, $seed->sessionUserId)
        );

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS be_users (
                uid INTEGER PRIMARY KEY,
                pid INTEGER DEFAULT 0,
                username TEXT DEFAULT \'\',
                password TEXT DEFAULT \'\',
                admin INTEGER DEFAULT 0,
                disable INTEGER DEFAULT 0,
                deleted INTEGER DEFAULT 0,
                tstamp INTEGER DEFAULT 0,
                crdate INTEGER DEFAULT 0
            )'
        );

        $statement = $connection->prepare(
            'INSERT OR IGNORE INTO be_users (uid, pid, username, password, admin, disable, deleted, tstamp, crdate)
             VALUES (:uid, :pid, :username, :password, :admin, :disable, :deleted, :tstamp, :crdate)'
        );
        $statement->execute(SeededBackendUser::row($seed->sessionUserId));
    }

    #[\Override]
    public function finaliseTemplate(string $fingerprint): void
    {
        $connection = $this->connect($this->templateFile());
        $connection->exec('CREATE TABLE IF NOT EXISTS playwright_seed (fingerprint TEXT NOT NULL)');
        $connection->exec('DELETE FROM playwright_seed');

        $statement = $connection->prepare('INSERT INTO playwright_seed (fingerprint) VALUES (:fingerprint)');
        $statement->execute(['fingerprint' => $fingerprint]);
    }

    #[\Override]
    public function materialise(string $testId): void
    {
        $this->ensureDirectory();

        // The exception carries the path, so the warning copy() would emit adds
        // nothing but noise.
        if (!@copy($this->templateFile(), $this->fileFor($testId))) {
            throw new \RuntimeException(sprintf('Could not copy the template to "%s".', $this->fileFor($testId)));
        }
    }

    #[\Override]
    public function exists(string $testId): bool
    {
        return is_file($this->fileFor($testId));
    }

    #[\Override]
    public function drop(string $testId): void
    {
        $this->remove($this->fileFor($testId), 'test database');
    }

    #[\Override]
    public function hasSeededSession(
        string $testId,
        string $plainSessionId,
        int $sessionUserId,
    ): bool {
        $file = $this->fileFor($testId);
        if (!is_file($file)) {
            return false;
        }

        $connection = $this->connect($file);

        try {
            $statement = $connection->prepare(
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
        $file = $this->fileFor($testId);
        if (!is_file($file)) {
            return ['ok' => false, 'detail' => sprintf('Test database %s does not exist.', $file)];
        }

        try {
            $this->connect($file)->query('SELECT 1');
        } catch (\PDOException $exception) {
            return ['ok' => false, 'detail' => sprintf('Test database %s unreadable: %s', $file, $exception->getMessage())];
        }

        return ['ok' => true, 'detail' => sprintf('Test database %s is readable.', $file)];
    }

    private function templateFile(): string
    {
        return $this->directory . '/' . self::TEMPLATE_NAME . '.sqlite';
    }

    private function fileFor(string $testId): string
    {
        return $this->directory . '/' . DatabaseName::forTestIdChecked($testId) . '.sqlite';
    }

    // An absent file is the goal; one that survives the unlink is not, or a stale
    // database gets stamped as freshly prepared.
    private function remove(string $file, string $what): void
    {
        if (!file_exists($file)) {
            return;
        }

        @unlink($file);

        if (file_exists($file)) {
            throw new \RuntimeException(sprintf('Could not remove the %s at "%s".', $what, $file));
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created.', $this->directory));
        }
    }

    private function connect(string $file): \PDO
    {
        Engine::Sqlite->assertCanProvision();

        $connection = new \PDO('sqlite:' . $file);
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }
}
