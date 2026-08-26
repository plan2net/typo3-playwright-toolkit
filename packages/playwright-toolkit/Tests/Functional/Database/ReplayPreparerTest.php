<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\ReplayPreparer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReplayPreparerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    // Replay rebuilds a database on the db-test service; a sqlite file is not one.
    #[Test]
    public function refusesToReplayOnSqlite(): void
    {
        $this->expectExceptionMessageMatches('/sqlite/i');

        $this->get(ReplayPreparer::class)->prepare();
    }

    #[Test]
    public function namesTheDbTestServiceInTheRefusal(): void
    {
        $this->expectExceptionMessageMatches('/db-test/');

        $this->get(ReplayPreparer::class)->prepare();
    }
}
