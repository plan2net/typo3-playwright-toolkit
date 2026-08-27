<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Cleanup;

use Plan2net\PlaywrightToolkit\Database\Cleanup\CleanupOutcome;
use Plan2net\PlaywrightToolkit\Database\Cleanup\DatabaseCleanup;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseCleanupTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    /**
     * @var string
     */
    private const DATABASE = 'db' . self::TEST_ID;
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $root;

    private LockFiles $lockFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pw-cleanup-' . uniqid('', true);
        mkdir($this->root . '/locks', 0777, true);
        mkdir($this->root . '/databases', 0777, true);
        $this->lockFiles = new LockFiles($this->root . '/locks');
    }

    protected function tearDown(): void
    {
        foreach (['/locks', '/databases'] as $directory) {
            foreach (glob($this->root . $directory . '/*') ?: [] as $file) {
                is_dir($file) ? rmdir($file) : unlink($file);
            }
            @rmdir($this->root . $directory);
        }
        @rmdir($this->root);

        foreach (glob($this->processedFolder() . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->processedFolder());

        parent::tearDown();
    }

    #[Test]
    public function dropsAClaimedDatabaseAndReleasesItsClaim(): void
    {
        $this->claim();
        $this->createDatabaseFile();

        self::assertSame(CleanupOutcome::Dropped, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertFileDoesNotExist($this->databaseFile());
        self::assertFileDoesNotExist($this->lockFiles->claim(self::DATABASE));
    }

    #[Test]
    public function removesTheProcessedImageFolderOfTheDroppedDatabase(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $folder = $this->processedFolder();
        mkdir($folder, 0777, true);
        touch($folder . '/csm_image_0123456789.jpg');

        self::assertSame(CleanupOutcome::Dropped, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertDirectoryDoesNotExist($folder);
    }

    // Preserved on failure, and for replay, so the images have to stay readable.
    #[Test]
    public function keepsTheProcessedImageFolderWhenTheDatabaseSurvives(): void
    {
        $folder = $this->processedFolder();
        mkdir($folder, 0777, true);

        self::assertSame(CleanupOutcome::Absent, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertDirectoryExists($folder);
    }

    // A lost response must not turn a completed drop into an error on retry.
    #[Test]
    public function reportsAbsentWhenThereIsNothingLeftToDo(): void
    {
        self::assertSame(CleanupOutcome::Absent, $this->cleanup()->drop($this->driver(), self::TEST_ID));
    }

    #[Test]
    public function droppingTwiceYieldsDroppedThenAbsent(): void
    {
        $this->claim();
        $this->createDatabaseFile();

        $cleanup = $this->cleanup();

        self::assertSame(CleanupOutcome::Dropped, $cleanup->drop($this->driver(), self::TEST_ID));
        self::assertSame(CleanupOutcome::Absent, $cleanup->drop($this->driver(), self::TEST_ID));
    }

    #[Test]
    public function refusesToTouchADatabaseItNeverClaimed(): void
    {
        $this->createDatabaseFile();

        self::assertSame(CleanupOutcome::Unclaimed, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertFileExists($this->databaseFile(), 'a database we do not own was dropped');
    }

    #[Test]
    public function releasesAClaimWhoseDatabaseIsAlreadyGone(): void
    {
        $this->claim();

        self::assertSame(CleanupOutcome::Dropped, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertFileDoesNotExist($this->lockFiles->claim(self::DATABASE));
    }

    #[Test]
    #[DataProvider('namesThatMustNeverBeDropped')]
    public function refusesANameThatIsNotContractShaped(string $testId): void
    {
        self::assertSame(CleanupOutcome::Refused, $this->cleanup()->drop($this->driver(), $testId));
    }

    /**
     * @return list<array{string}>
     */
    public static function namesThatMustNeverBeDropped(): array
    {
        return [
            [''],
            ['abcd1234efgh5678'],
            ['ABCD1234EFGH567'],
            ['ABCD1234EFGH56789'],
            ['../../etc/passwd'],
            ['ABCD1234EFGH-678'],
            ['playwright_db_template'],
            // Provisioning reaches the bare "db" by this name; dropping never may.
            [DatabaseName::REPLAY_TEST_ID],
        ];
    }

    #[Test]
    public function keepsTheClaimWhenTheDropFails(): void
    {
        $this->claim();
        // A directory where the file belongs survives unlink, so drop() throws.
        mkdir($this->databaseFile());

        self::assertSame(CleanupOutcome::Failed, $this->cleanup()->drop($this->driver(), self::TEST_ID));
        self::assertFileExists($this->lockFiles->claim(self::DATABASE), 'a failed drop released its claim');
    }

    // Provisioning holds this lock across materialise() and the claim, so cleanup
    // must wait rather than drop a database that is being rebuilt.
    #[Test]
    public function reportsFailedRatherThanDroppingWhileProvisioningHoldsTheLock(): void
    {
        $this->claim();
        $this->createDatabaseFile();

        $handle = fopen($this->lockFiles->createLock(self::DATABASE), 'c');
        self::assertNotFalse($handle);
        flock($handle, LOCK_EX);

        try {
            $outcome = $this->cleanup(lockTimeoutMs: 200)->drop($this->driver(), self::TEST_ID);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertSame(CleanupOutcome::Failed, $outcome);
        self::assertFileExists($this->databaseFile(), 'dropped a database while it was locked');
    }

    #[Test]
    public function dropsOnceTheLockIsFree(): void
    {
        $this->claim();
        $this->createDatabaseFile();

        $handle = fopen($this->lockFiles->createLock(self::DATABASE), 'c');
        self::assertNotFalse($handle);
        flock($handle, LOCK_EX);
        flock($handle, LOCK_UN);
        fclose($handle);

        self::assertSame(CleanupOutcome::Dropped, $this->cleanup()->drop($this->driver(), self::TEST_ID));
    }

    #[Test]
    public function sweepReclaimsAClaimOlderThanTheCutoff(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(hours: 3);

        $sweep = $this->cleanup()->sweep($this->driver(), [], 0, 3600000);

        self::assertSame([self::TEST_ID => CleanupOutcome::Dropped], $sweep['outcomes']);
        self::assertFileDoesNotExist($this->databaseFile());
    }

    #[Test]
    public function sweepKeepsAClaimYoungerThanTheCutoff(): void
    {
        $this->claim();
        $this->createDatabaseFile();

        $sweep = $this->cleanup()->sweep($this->driver(), [], 0, 3600000);

        self::assertSame([], $sweep['outcomes']);
        self::assertSame(1, $sweep['kept']);
        self::assertFileExists($this->databaseFile());
    }

    #[Test]
    public function sweepKeepsWhatTheCallerSaysIsLive(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(hours: 3);

        $sweep = $this->cleanup()->sweep($this->driver(), [self::TEST_ID], 0, 3600000);

        self::assertSame([], $sweep['outcomes']);
        self::assertSame(1, $sweep['kept']);
        self::assertFileExists($this->databaseFile(), 'reclaimed a live run\'s database');
    }

    // The floor is the server's; a request may be more conservative, never less.
    #[Test]
    public function sweepClampsARequestedAgeUpToTheFloor(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(minutes: 5);

        $sweep = $this->cleanup()->sweep($this->driver(), [], 1000, 3600000);

        self::assertSame(3600000, $sweep['cutoffMs'], 'the requested age was not clamped up');
        self::assertSame([], $sweep['outcomes']);
        self::assertFileExists($this->databaseFile(), 'swept a database younger than the floor');
    }

    #[Test]
    public function sweepHonoursARequestForSomethingOlderThanTheFloor(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(hours: 3);

        $sweep = $this->cleanup()->sweep($this->driver(), [], 86400000, 3600000);

        self::assertSame(86400000, $sweep['cutoffMs']);
        self::assertSame([], $sweep['outcomes'], 'swept a database younger than the requested age');
    }

    // A claim whose name is not contract-shaped can never be dropped, so the sweep
    // must not report it either — "db-db.lock" is the case that matters.
    #[Test]
    public function sweepIgnoresAClaimThatIsNotContractShaped(): void
    {
        touch($this->lockFiles->claim('db'));
        touch($this->lockFiles->claim('playwright_db_template'));
        foreach (['db', 'playwright_db_template'] as $name) {
            touch($this->root . '/locks/db-' . $name . '.lock', time() - 10800);
        }

        $sweep = $this->cleanup()->sweep($this->driver(), [], 0, 3600000);

        self::assertSame([], $sweep['outcomes']);
    }

    /**
     * A caller reaching the endpoint directly leaves no other trace of what was
     * destroyed, so every drop, refusal and reclamation has to be logged.
     */
    #[Test]
    public function logsEveryDropRefusalAndReclamation(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /**
             * Untyped $message on purpose: psr/log 1, which TYPO3 11 pins, declares
             * it untyped, and narrowing it here is a fatal.
             *
             * @param mixed             $level
             * @param string|\Stringable $message
             * @param array<mixed>      $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };

        $cleanup = $this->cleanup();
        $cleanup->setLogger($logger);

        $this->claim();
        $this->createDatabaseFile();
        $cleanup->drop($this->driver(), self::TEST_ID);
        $cleanup->drop($this->driver(), 'not-a-test-id');

        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(hours: 3);
        $cleanup->sweep($this->driver(), [], 0, 3600000);

        $joined = implode("\n", $logger->lines);
        self::assertStringContainsString('Dropped a test database', $joined);
        self::assertStringContainsString('Refused a cleanup request', $joined);
        self::assertStringContainsString('Reclaimed an orphaned test database', $joined);
        self::assertStringContainsString(self::DATABASE, $joined, 'the log does not name the database');
    }

    /**
     * The age decision must be remade under the lock. A second process holds the
     * lock and refreshes the claim before releasing it — exactly what provisioning
     * does — so a sweep that decided "old" beforehand must not act on that.
     */
    #[Test]
    public function sweepLeavesADatabaseWhoseClaimWasRefreshedWhileItWaited(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        $this->ageClaim(hours: 3);

        $holder = $this->startClaimRefresher();

        try {
            $sweep = $this->cleanup(lockTimeoutMs: 5000)->sweep($this->driver(), [], 0, 3600000);
        } finally {
            proc_close($holder);
        }

        self::assertSame([], $sweep['outcomes'], 'swept a database that had just been re-claimed');
        self::assertSame(1, $sweep['kept']);
        self::assertFileExists($this->databaseFile(), 'dropped a freshly provisioned database');
    }

    #[Test]
    public function reportsFailedWhenTheClaimSurvivesTheDrop(): void
    {
        $this->claim();
        $this->createDatabaseFile();
        // A directory in place of the claim file cannot be unlinked.
        unlink($this->lockFiles->claim(self::DATABASE));
        mkdir($this->lockFiles->claim(self::DATABASE));

        $outcome = $this->cleanup()->drop($this->driver(), self::TEST_ID);

        self::assertSame(CleanupOutcome::Failed, $outcome, 'reported success while leaving a stale claim');
    }

    /**
     * @return resource
     */
    private function startClaimRefresher()
    {
        $script = $this->root . '/refresh-claim.php';
        file_put_contents($script, sprintf(
            '<?php $lock = fopen(%s, "c"); flock($lock, LOCK_EX); usleep(400000);'
            . ' touch(%s); flock($lock, LOCK_UN); fclose($lock);',
            var_export($this->lockFiles->createLock(self::DATABASE), true),
            var_export($this->lockFiles->claim(self::DATABASE), true)
        ));

        $process = proc_open(['php', $script], [], $pipes);
        self::assertIsResource($process);
        // Give it time to take the lock, or the sweep wins the race and the test
        // proves nothing.
        usleep(200000);

        return $process;
    }

    private function ageClaim(int $hours = 0, int $minutes = 0): void
    {
        touch($this->lockFiles->claim(self::DATABASE), time() - $hours * 3600 - $minutes * 60);
    }

    private function cleanup(int $lockTimeoutMs = 2000): DatabaseCleanup
    {
        return new DatabaseCleanup(
            $this->lockFiles,
            $lockTimeoutMs,
            $this->get(ProcessedFileIsolation::class)
        );
    }

    // Read from the storage record rather than assuming fileadmin: a project can
    // point its storage anywhere, and a literal here would quietly assert nothing.
    private function processedFolder(): string
    {
        $configuration = $this->get(StorageRepository::class)->getDefaultStorage()?->getConfiguration() ?? [];
        $basePath = trim((string) ($configuration['basePath'] ?? ''), '/');

        return rtrim(Environment::getPublicPath(), '/') . '/' . $basePath . '/'
            . ProcessedFileIsolation::folderFor(self::TEST_ID);
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver($this->root . '/databases');
    }

    private function claim(): void
    {
        touch($this->lockFiles->claim(self::DATABASE));
    }

    private function createDatabaseFile(): void
    {
        touch($this->databaseFile());
    }

    private function databaseFile(): string
    {
        return $this->root . '/databases/' . self::DATABASE . '.sqlite';
    }
}
