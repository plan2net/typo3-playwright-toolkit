<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\TestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseNameTest extends TestCase
{
    #[Test]
    public function derivesTheNameFromATestId(): void
    {
        self::assertSame('dbABCD1234EFGH5678', DatabaseName::forTestId('ABCD1234EFGH5678'));
    }

    #[Test]
    public function mapsTheReplayTestIdToTheBaseDatabase(): void
    {
        self::assertSame('db', DatabaseName::forTestId(DatabaseName::REPLAY_TEST_ID));
    }

    #[Test]
    public function theReplayTestIdIsContractShaped(): void
    {
        self::assertMatchesRegularExpression(TestContext::TEST_ID_PATTERN, DatabaseName::REPLAY_TEST_ID);
    }

    #[Test]
    public function provisioningAcceptsTheReplayDatabase(): void
    {
        DatabaseName::assertProvisionable(DatabaseName::forTestId(DatabaseName::REPLAY_TEST_ID));

        self::assertSame('db', DatabaseName::forTestIdChecked(DatabaseName::REPLAY_TEST_ID));
    }

    // Only the CLI may rebuild it; nothing that reaches the wire drops a bare "db".
    #[Test]
    public function theReplayDatabaseIsNeverDroppable(): void
    {
        self::assertFalse(DatabaseName::isDroppable(DatabaseName::forTestId(DatabaseName::REPLAY_TEST_ID)));
    }

    #[Test]
    public function anEmptyTestIdStillReachesNoDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DatabaseName::forTestIdChecked('');
    }

    #[Test]
    public function readsTheTestIdBackOut(): void
    {
        self::assertSame('ABCD1234EFGH5678', DatabaseName::testIdOf('dbABCD1234EFGH5678'));
    }

    // PHP's $ matches before a final newline, so the pattern needs \z to hold.
    #[Test]
    public function refusesANameCarryingATrailingNewline(): void
    {
        self::assertFalse(DatabaseName::isDroppable("dbABCD1234EFGH5678\n"));
        self::assertFalse(DatabaseName::isProvisionable("dbABCD1234EFGH5678\n"));
    }

    #[Test]
    public function acceptsAContractShapedName(): void
    {
        self::assertTrue(DatabaseName::isDroppable('dbABCD1234EFGH5678'));
    }

    /**
     * The name is interpolated straight into CREATE/DROP DATABASE, so anything
     * off-contract has to be refused rather than escaped.
     */
    #[Test]
    #[DataProvider('namesThatMustNeverBeDropped')]
    public function refusesAnythingOffContract(string $name): void
    {
        self::assertFalse(DatabaseName::isDroppable($name));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesThatMustNeverBeDropped(): array
    {
        return [
            'the base database' => ['db'],
            'lowercase test id' => ['dbabcd1234efgh5678'],
            'too short' => ['dbABCD1234EFGH567'],
            'too long' => ['dbABCD1234EFGH56789'],
            'another prefix' => ['xxABCD1234EFGH5678'],
            'a statement terminator' => ['dbABCD1234EFGH5678; DROP DATABASE db'],
            'a quote' => ['db"ABCD1234EFGH567'],
            'whitespace' => ['dbABCD1234EFGH5678 '],
            'empty' => [''],
        ];
    }

    /**
     * Provisioning legitimately selects the base database when no test ID was
     * sent; dropping never may. Same contract, two different questions.
     */
    #[Test]
    public function allowsTheBaseDatabaseOnlyForProvisioning(): void
    {
        self::assertTrue(DatabaseName::isProvisionable('db'));
        self::assertFalse(DatabaseName::isDroppable('db'));
    }

    #[Test]
    public function refusesAMalformedNameForProvisioningToo(): void
    {
        self::assertFalse(DatabaseName::isProvisionable('db"; DROP DATABASE x'));
    }

    #[Test]
    public function assertingAnInvalidNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DatabaseName::assertProvisionable('db; DROP DATABASE x');
    }

    #[Test]
    public function theCheckedFormRefusesAnEmptyTestId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DatabaseName::forTestIdChecked('');
    }

    #[Test]
    public function theCheckedFormReturnsTheName(): void
    {
        self::assertSame('dbABCD1234EFGH5678', DatabaseName::forTestIdChecked('ABCD1234EFGH5678'));
    }

    #[Test]
    public function assertingAValidNameDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        DatabaseName::assertProvisionable('dbABCD1234EFGH5678');
    }

    // The prefix belongs to the wire contract, not to each class that builds it.
    #[Test]
    public function usesTheContractPrefix(): void
    {
        self::assertStringStartsWith(TestContext::DATABASE_PREFIX, DatabaseName::forTestId('ABCD1234EFGH5678'));
    }

    // The droppable pattern spells the test ID out rather than deriving it, so
    // this is what stops it drifting from the contract it copies.
    #[Test]
    #[DataProvider('identifiersOnAndOffContract')]
    public function acceptsExactlyTheTestIdsTheContractAccepts(string $testId): void
    {
        self::assertSame(
            1 === preg_match(TestContext::TEST_ID_PATTERN, $testId),
            DatabaseName::isDroppable(DatabaseName::forTestId($testId))
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function identifiersOnAndOffContract(): array
    {
        return [
            ['ABCD1234EFGH5678'],
            ['AAAAAAAAAAAAAAAA'],
            ['0000000000000000'],
            ['abcd1234efgh5678'],
            ['ABCD1234EFGH567'],
            ['ABCD1234EFGH56789'],
            ['ABCD-234EFGH5678'],
            [''],
        ];
    }
}
