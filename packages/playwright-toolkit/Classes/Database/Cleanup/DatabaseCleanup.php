<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Cleanup;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

// Not readonly: LoggerAwareTrait needs a settable property, which is how TYPO3
// injects the logger (autoconfigure, as core's own middlewares do).
final class DatabaseCleanup implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    // Every drop, refusal and reclamation is logged: a caller reaching the
    // endpoint directly leaves no other trace of what was destroyed.
    public function __construct(
        private readonly LockFiles $lockFiles,
        private readonly int $lockTimeoutMs = 5000,
        private readonly ?ProcessedFileIsolation $processedFiles = null,
    ) {
    }

    public function drop(TestDatabaseDriver $driver, string $testId): CleanupOutcome
    {
        return $this->dropWithinLock($driver, $testId) ?? CleanupOutcome::Failed;
    }

    /**
     * @param list<string> $testIds
     *
     * @return array<string, CleanupOutcome> keyed by test ID, input order preserved
     */
    public function dropAll(TestDatabaseDriver $driver, array $testIds): array
    {
        $outcomes = [];
        foreach ($testIds as $testId) {
            $outcomes[$testId] = $this->drop($driver, $testId);
        }

        return $outcomes;
    }

    /**
     * $requestedAgeMs may only raise $floorMs, so no caller can sweep a run that
     * started moments ago.
     *
     * @param list<string> $keepTestIds test IDs belonging to live runs
     *
     * @return array{outcomes: array<string, CleanupOutcome>, kept: int, cutoffMs: int}
     *                                                                                  outcomes keyed by test ID, kept counts the claims deliberately left
     *                                                                                  alone, cutoffMs is the age actually applied after clamping
     */
    public function sweep(
        TestDatabaseDriver $driver,
        array $keepTestIds,
        int $requestedAgeMs,
        int $floorMs,
        ?int $nowMs = null,
    ): array {
        $cutoffMs = max($requestedAgeMs, $floorMs);
        $nowMs ??= (int) (microtime(true) * 1000);
        $live = array_fill_keys($keepTestIds, true);

        $outcomes = [];
        $kept = 0;

        foreach ($this->lockFiles->claimedDatabaseNames() as $databaseName) {
            $testId = DatabaseName::testIdOf($databaseName);

            // A claim that could never be dropped must not be reported either.
            if (!DatabaseName::isDroppable($databaseName)) {
                continue;
            }

            if (isset($live[$testId])) {
                ++$kept;
                continue;
            }

            // The age is re-checked inside the lock, so this is only a cheap
            // pre-filter: provisioning may refresh the claim while we wait for the
            // lock, and dropping then would destroy a live test's database.
            $ageMs = $this->lockFiles->claimAgeMs($databaseName, $nowMs);
            if (null === $ageMs || $ageMs < $cutoffMs) {
                ++$kept;
                continue;
            }

            $outcome = $this->dropWithinLock($driver, $testId, $cutoffMs);
            if (null === $outcome) {
                ++$kept;
                continue;
            }

            $this->logger?->notice('Reclaimed an orphaned test database', [
                'database' => $databaseName,
                'outcome' => $outcome->value,
                'ageMs' => $ageMs,
            ]);
            $outcomes[$testId] = $outcome;
        }

        return ['outcomes' => $outcomes, 'kept' => $kept, 'cutoffMs' => $cutoffMs];
    }

    /**
     * @param int|null $minimumAgeMs when set, the claim is re-checked under the
     *                               lock and left alone if it is younger
     *
     * @return CleanupOutcome|null null means "deliberately left alone"
     */
    private function dropWithinLock(
        TestDatabaseDriver $driver,
        string $testId,
        ?int $minimumAgeMs = null,
    ): ?CleanupOutcome {
        $databaseName = DatabaseName::forTestId($testId);

        // Deliberately the strict form: provisioning tolerates a bare "db" for the
        // base database, nothing that drops ever may.
        if (!DatabaseName::isDroppable($databaseName)) {
            $this->logger?->warning('Refused a cleanup request for an unexpected name', [
                'testId' => $testId,
            ]);

            return CleanupOutcome::Refused;
        }

        $outcome = null;

        try {
            // Provisioning holds this exclusively across the claim and
            // materialise(), so waiting here is what stops a drop mid-rebuild.
            $acquired = $this->lockFiles->exclusivelyWithin(
                $this->lockFiles->databaseLock($databaseName),
                $this->lockTimeoutMs,
                function () use ($driver, $testId, $databaseName, $minimumAgeMs, &$outcome): void {
                    $outcome = $this->dropUnderLock($driver, $testId, $databaseName, $minimumAgeMs);
                }
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Could not take the create lock', [
                'database' => $databaseName,
                'exception' => $exception->getMessage(),
            ]);

            return CleanupOutcome::Failed;
        }

        if (!$acquired) {
            $this->logger?->error('Timed out waiting for the create lock', [
                'database' => $databaseName,
                'timeoutMs' => $this->lockTimeoutMs,
            ]);

            return CleanupOutcome::Failed;
        }

        return $outcome;
    }

    private function dropUnderLock(
        TestDatabaseDriver $driver,
        string $testId,
        string $databaseName,
        ?int $minimumAgeMs,
    ): ?CleanupOutcome {
        $claim = $this->lockFiles->claim($databaseName);

        // Re-checked inside the lock: provisioning may have claimed the name while
        // this request waited for it.
        if (!file_exists($claim)) {
            try {
                $exists = $driver->exists($testId);
            } catch (\Throwable $exception) {
                $this->logger?->error('Could not tell whether the database exists', [
                    'database' => $databaseName,
                    'exception' => $exception->getMessage(),
                ]);

                return CleanupOutcome::Failed;
            }

            if ($exists) {
                $this->logger?->warning('A database of this name exists but was never claimed here', [
                    'database' => $databaseName,
                ]);

                return CleanupOutcome::Unclaimed;
            }

            return CleanupOutcome::Absent;
        }

        // The age decision is remade here, under the lock: a claim refreshed while
        // we waited belongs to a run that has just started using it.
        if (null !== $minimumAgeMs) {
            $ageMs = $this->lockFiles->claimAgeMs($databaseName, (int) (microtime(true) * 1000));
            if (null === $ageMs || $ageMs < $minimumAgeMs) {
                $this->logger?->info('Left a freshly claimed database alone', [
                    'database' => $databaseName,
                    'ageMs' => $ageMs,
                ]);

                return null;
            }
        }

        try {
            $driver->drop($testId);
        } catch (\Throwable $exception) {
            // The claim stays: a database that may still exist has to remain
            // discoverable, and one wasted retry is cheaper than an orphan.
            $this->logger?->error('Could not drop the test database', [
                'database' => $databaseName,
                'exception' => $exception->getMessage(),
            ]);

            return CleanupOutcome::Failed;
        }

        // A surviving claim would keep naming a database that is gone, so the
        // caller is told to retry rather than being told this succeeded.
        if (!@unlink($claim) && file_exists($claim)) {
            $this->logger?->error('Dropped the database but could not release its claim', [
                'database' => $databaseName,
                'claim' => $claim,
            ]);

            return CleanupOutcome::Failed;
        }

        $this->processedFiles?->remove($testId);

        $this->logger?->notice('Dropped a test database', ['database' => $databaseName]);

        return CleanupOutcome::Dropped;
    }
}
