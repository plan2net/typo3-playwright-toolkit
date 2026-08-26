<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\SeededSession;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Only core ever reads the seeded row back, so agreeing on the hash is what makes
// it findable at all. TYPO3 14 changed the formula.
final class SeededSessionCoreContractTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function hashesTheSessionIdTheWayCoreLooksItUp(): void
    {
        self::assertSame(
            GeneralUtility::makeInstance(DatabaseSessionBackend::class)->hash('playwright_test_session'),
            SeededSession::hashedSessionId('playwright_test_session')
        );
    }

    #[Test]
    public function aChangedPlainSessionIdProducesADifferentSessionId(): void
    {
        self::assertNotSame(
            SeededSession::hashedSessionId('playwright_test_session'),
            SeededSession::hashedSessionId('another_test_session')
        );
    }

    #[Test]
    public function theRowCarriesEveryColumnAnInsertNeeds(): void
    {
        self::assertSame(
            [
                'ses_id' => SeededSession::hashedSessionId('playwright_test_session'),
                'ses_iplock' => SeededSession::IPLOCK,
                'ses_userid' => 1,
                'ses_tstamp' => 2147483647,
                'ses_data' => serialize([]),
            ],
            SeededSession::row('playwright_test_session', 1)
        );
    }

    #[Test]
    public function theCriteriaAreTheRowWithoutWhatMayDrift(): void
    {
        $criteria = SeededSession::criteria('playwright_test_session', 1);

        self::assertSame(['ses_id', 'ses_iplock', 'ses_userid'], array_keys($criteria));
        self::assertSame(
            array_intersect_key(SeededSession::row('playwright_test_session', 1), $criteria),
            $criteria
        );
    }
}
