<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Cleanup;

use TYPO3\CMS\Core\Core\Environment;

final class LockFiles
{
    /**
     * @var string
     */
    public const TEMPLATE_LOCK_FILE = 'template-build.lock';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public static function inVarPath(): self
    {
        return new self(Environment::getVarPath() . '/test-locks');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function ensureDirectory(): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->directory));
        }

        return $this->directory;
    }

    /**
     * Cleanup discovers test databases by globbing "db-*.lock", so only a claim
     * may carry that prefix.
     */
    public function claim(string $databaseName): string
    {
        return $this->directory . '/db-' . $databaseName . '.lock';
    }

    public function createLock(string $databaseName): string
    {
        return $this->directory . '/create-' . $databaseName . '.lock';
    }

    public function templateLock(): string
    {
        return $this->directory . '/' . self::TEMPLATE_LOCK_FILE;
    }

    /**
     * @return resource
     */
    public function open(string $lockFile)
    {
        $this->ensureDirectory();

        $handle = fopen($lockFile, 'c');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Could not open the lock file "%s"', $lockFile));
        }

        return $handle;
    }

    /**
     * @return list<string> database names this extension has claimed
     */
    public function claimedDatabaseNames(): array
    {
        $claims = glob($this->directory . '/db-*.lock');

        // glob() answers false on an unreadable directory and [] when there is
        // simply nothing — reporting "no orphans" for the first would leave every
        // test database behind.
        if (false === $claims) {
            throw new \RuntimeException(sprintf('Could not read the lock directory "%s"', $this->directory));
        }

        $names = [];
        foreach ($claims as $file) {
            $names[] = substr(basename($file), strlen('db-'), -strlen('.lock'));
        }
        sort($names);

        return $names;
    }

    public function claimAgeMs(string $databaseName, int $nowMs): ?int
    {
        $claim = $this->claim($databaseName);
        $modified = @filemtime($claim);

        return false === $modified ? null : $nowMs - $modified * 1000;
    }

    /** Kept in the claim file, which cleanup only ever checks for existence. */
    public function writeLabel(string $databaseName, string $label): void
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $label));

        @file_put_contents($this->claim($databaseName), $flat);
    }

    public function readLabel(string $databaseName): string
    {
        $contents = @file_get_contents($this->claim($databaseName));

        return false === $contents ? '' : trim($contents);
    }
}
