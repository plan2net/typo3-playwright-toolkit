<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit;

use Plan2net\PlaywrightToolkit\SiteName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiteNameTest extends TestCase
{
    #[Test]
    public function marksTheSiteWithTheTestIdSoTheBackendShowsWhereYouAre(): void
    {
        self::assertSame('Acme [ABCD1234EFGH5678]', SiteName::marked('Acme', 'ABCD1234EFGH5678'));
    }

    #[Test]
    public function leavesTheNameAloneWithoutATestId(): void
    {
        self::assertSame('Acme', SiteName::marked('Acme', ''));
    }

    /** Two inspect sessions open at once must not end up looking identical. */
    #[Test]
    public function marksTwoTestIdsDifferently(): void
    {
        self::assertNotSame(
            SiteName::marked('Acme', 'AAAA1111AAAA1111'),
            SiteName::marked('Acme', 'BBBB2222BBBB2222')
        );
    }

    /** Reapplying on a later boot must not stack a second marker on. */
    #[Test]
    public function doesNotMarkANameItAlreadyMarked(): void
    {
        $once = SiteName::marked('Acme', 'ABCD1234EFGH5678');

        self::assertSame($once, SiteName::marked($once, 'ABCD1234EFGH5678'));
    }

    #[Test]
    public function replacesTheMarkerOfAnotherTestId(): void
    {
        $other = SiteName::marked('Acme', 'AAAA1111AAAA1111');

        self::assertSame('Acme [BBBB2222BBBB2222]', SiteName::marked($other, 'BBBB2222BBBB2222'));
    }

    #[Test]
    public function showsTheTestNameBesideTheIdWhenThereIsOne(): void
    {
        self::assertSame(
            'Acme [accordion-simple · ABCD1234EFGH5678]',
            SiteName::marked('Acme', 'ABCD1234EFGH5678', 'accordion-simple')
        );
    }

    #[Test]
    public function fallsBackToTheIdAloneWhenNoNameWasStored(): void
    {
        self::assertSame('Acme [ABCD1234EFGH5678]', SiteName::marked('Acme', 'ABCD1234EFGH5678', ''));
    }

    #[Test]
    public function doesNotStackAMarkerThatCarriesAName(): void
    {
        $once = SiteName::marked('Acme', 'ABCD1234EFGH5678', 'accordion-simple');

        self::assertSame($once, SiteName::marked($once, 'ABCD1234EFGH5678', 'accordion-simple'));
    }

    #[Test]
    public function replacesANamedMarkerWithAPlainOne(): void
    {
        $named = SiteName::marked('Acme', 'AAAA1111AAAA1111', 'accordion-simple');

        self::assertSame('Acme [BBBB2222BBBB2222]', SiteName::marked($named, 'BBBB2222BBBB2222'));
    }
}
