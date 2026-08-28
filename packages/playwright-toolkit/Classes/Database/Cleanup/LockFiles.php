<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Cleanup;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use TYPO3\CMS\Core\Core\Environment;

final class LockFiles
{
    /**
     * @var string
     */
    public const TEMPLATE_LOCK = 'playwright-template-build';

    private ?LockFactory $lockFactory = null;

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

    public function databaseLock(string $databaseName): string
    {
        return 'playwright-create-' . $databaseName;
    }

    /**
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    public function exclusively(string $key, callable $body): mixed
    {
        return $this->holding($key, false, $body);
    }

    /**
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    public function shared(string $key, callable $body): mixed
    {
        return $this->holding($key, true, $body);
    }

    /**
     * @param callable(): void $body
     *
     * @return bool false when the lock stayed taken for the whole timeout
     */
    public function exclusivelyWithin(string $key, float $timeoutMs, callable $body): bool
    {
        $lock = $this->lock($key);
        $deadline = microtime(true) + $timeoutMs / 1000;

        do {
            if ($lock->acquire()) {
                try {
                    $body();

                    return true;
                } finally {
                    $lock->release();
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        return false;
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

    /**
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    private function holding(string $key, bool $sharedAccess, callable $body): mixed
    {
        $lock = $this->lock($key);

        // Blocking: a clone waits for a running preparation.
        $sharedAccess ? $lock->acquireRead(true) : $lock->acquire(true);

        try {
            return $body();
        } finally {
            $lock->release();
        }
    }

    private function lock(string $key): SharedLockInterface
    {
        if (null === $this->lockFactory) {
            $this->lockFactory = new LockFactory(new FlockStore($this->ensureDirectory()));
        }

        // No expiry: a long build must not lose its lock.
        return $this->lockFactory->createLock($key, null);
    }
}
