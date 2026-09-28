<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Security\ProbeSignature;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;

final class ProbeSignatureTest extends TestCase
{
    #[Test]
    public function signsExactlyWhatTheContractFixtureRecords(): void
    {
        $fixture = ContractFixture::read('site-probe');

        self::assertSame($fixture['signature'], ProbeSignature::sign($fixture['secret'], $fixture['testId']));
    }

    #[Test]
    public function acceptsItsOwnSignatureOnly(): void
    {
        $signature = ProbeSignature::sign('secret', 'ABCD1234EFGH5678');

        self::assertTrue(ProbeSignature::verify('secret', 'ABCD1234EFGH5678', $signature));
        self::assertFalse(ProbeSignature::verify('secret', 'ZZZZ1234EFGH5678', $signature));
        self::assertFalse(ProbeSignature::verify('another secret', 'ABCD1234EFGH5678', $signature));
    }

    #[Test]
    public function refusesEverythingWithoutASecret(): void
    {
        self::assertFalse(ProbeSignature::verify('', 'ABCD1234EFGH5678', ProbeSignature::sign('', 'ABCD1234EFGH5678')));
    }
}
