<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TestDatabaseServiceTest extends TestCase
{
    /**
     * @var mixed[]
     */
    private const VARIABLES = [
        TestDatabaseService::HOST_VARIABLE,
        TestDatabaseService::PORT_VARIABLE,
        TestDatabaseService::USER_VARIABLE,
        TestDatabaseService::PASSWORD_VARIABLE,
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->originalEnvironment[$variable] = getenv($variable);
            putenv($variable);
            unset($_SERVER[$variable]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            if (is_string($value)) {
                putenv($variable . '=' . $value);
                continue;
            }
            putenv($variable);
            unset($_SERVER[$variable]);
        }

        parent::tearDown();
    }

    #[Test]
    public function readsTheServiceFromTheEnvironment(): void
    {
        putenv(TestDatabaseService::HOST_VARIABLE . '=some-other-host');
        putenv(TestDatabaseService::PORT_VARIABLE . '=6543');
        putenv(TestDatabaseService::USER_VARIABLE . '=tester');
        putenv(TestDatabaseService::PASSWORD_VARIABLE . '=secret');

        $service = TestDatabaseService::fromEnvironment(Engine::Postgres);

        self::assertSame('some-other-host', $service->host);
        self::assertSame(6543, $service->port);
        self::assertSame('tester', $service->user);
        self::assertSame('secret', $service->password);
    }

    #[Test]
    public function readsItFromServerWhenTheFpmPoolPassesItThatWay(): void
    {
        $_SERVER[TestDatabaseService::HOST_VARIABLE] = 'host-from-server';

        self::assertSame('host-from-server', TestDatabaseService::fromEnvironment(Engine::Postgres)->host);
    }

    #[Test]
    public function fallsBackToTheServiceTheAddOnShips(): void
    {
        $service = TestDatabaseService::fromEnvironment(Engine::Postgres);

        self::assertSame('db-test', $service->host);
        self::assertSame('db', $service->user);
    }

    #[Test]
    public function fallsBackToThePortOfTheEngineItWasAskedFor(): void
    {
        self::assertSame(5432, TestDatabaseService::fromEnvironment(Engine::Postgres)->port);
        self::assertSame(3306, TestDatabaseService::fromEnvironment(Engine::Mysql)->port);
    }

    #[Test]
    public function anEmptyVariableCountsAsUnset(): void
    {
        putenv(TestDatabaseService::HOST_VARIABLE . '=');

        self::assertSame('db-test', TestDatabaseService::fromEnvironment(Engine::Postgres)->host);
    }

    #[Test]
    public function refusesToInventAPortForSqlite(): void
    {
        $this->expectException(\LogicException::class);

        Engine::Sqlite->defaultPort();
    }

    // Provisioning always goes through PDO, so mysqli or pgsql alone cannot create
    // a test database. These are the extensions that have to be present.
    #[Test]
    public function namesThePdoExtensionProvisioningNeeds(): void
    {
        self::assertSame('pgsql', Engine::Postgres->pdoDriver());
        self::assertSame('mysql', Engine::Mysql->pdoDriver());
        self::assertSame('sqlite', Engine::Sqlite->pdoDriver());
    }

    #[Test]
    public function acceptsAnEngineWhosePdoDriverIsInstalled(): void
    {
        Engine::Sqlite->assertCanProvision();

        self::addToAssertionCount(1);
    }
}
