<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class TestContextTest extends TestCase
{
    private ?string $originalTestId = null;

    /** @var array<string, mixed>|null */
    private ?array $originalEnvironment = null;

    protected function setUp(): void
    {
        $this->originalTestId = $_SERVER[TestContext::TEST_ID_SERVER_KEY] ?? null;
        $this->originalEnvironment = self::captureEnvironment();

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/app',
            '/app/public',
            '/app/var',
            '/app/config',
            '/app/public/index.php',
            'UNIX',
        );
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[TestContext::TEST_ID_COOKIE]);

        if (null === $this->originalTestId) {
            unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        } else {
            $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $this->originalTestId;
        }

        // Reading the getters here instead would write setUp's own fake paths back.
        if (null !== $this->originalEnvironment) {
            Environment::initialize(
                $this->originalEnvironment['context'],
                $this->originalEnvironment['cli'],
                $this->originalEnvironment['composerMode'],
                $this->originalEnvironment['projectPath'],
                $this->originalEnvironment['publicPath'],
                $this->originalEnvironment['varPath'],
                $this->originalEnvironment['configPath'],
                $this->originalEnvironment['currentScript'],
                $this->originalEnvironment['os'],
            );
        }

        parent::tearDown();
    }

    #[Test]
    public function applyingWithoutATestIdLeavesTheProjectDatabaseAlone(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_pgsql',
            'dbname' => 'the_real_database',
        ];

        TestContext::applyDatabaseConnectionOverrides();

        self::assertSame(
            'the_real_database',
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname']
        );
    }

    #[Test]
    public function anEmptyTestIdYieldsNoOverridesAtAll(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        self::assertSame([], TestContext::databaseConnectionOverrides(['driver' => 'pdo_pgsql']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTestIds(): array
    {
        return [
            'not the contract pattern' => ['not-a-valid-id'],
            'sql metacharacters' => ['";DROP DATABASE x;--'],
            'path traversal' => ['../../etc/passwd'],
            'too short' => ['ABCD1234'],
            'lowercase' => ['abcd1234efgh5678'],
        ];
    }

    /**
     * A request the toolkit did not send must be left alone, whatever it carries in
     * the header. Changing the site's database off a malformed one is the danger,
     * and DatabaseName::assertProvisionable() is still there if one ever gets that far.
     */
    #[Test]
    #[DataProvider('malformedTestIds')]
    public function usesTheProjectDatabaseForAMalformedTestId(string $testId): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $testId;

        self::assertSame('', TestContext::testId());
        self::assertSame([], TestContext::databaseConnectionOverrides(['driver' => 'pdo_pgsql']));
    }

    #[Test]
    #[DataProvider('malformedTestIds')]
    public function reportsAMalformedTestIdSoItCanBeLogged(string $testId): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $testId;

        self::assertSame($testId, TestContext::malformedTestId());
    }

    #[Test]
    public function reportsNoMalformedTestIdForAWellFormedOne(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'ABCD1234EFGH5678';

        self::assertNull(TestContext::malformedTestId());
    }

    /**
     * A browser cannot send the header, so the inspect link leaves a cookie behind
     * instead. Test runs always send the header, so they never reach this.
     */
    #[Test]
    public function fallsBackToTheInspectCookieWhenNoHeaderWasSent(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $_COOKIE[TestContext::TEST_ID_COOKIE] = 'ABCD1234EFGH5678';

        self::assertSame('ABCD1234EFGH5678', TestContext::testId());
    }

    #[Test]
    public function prefersTheHeaderOverTheCookie(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = 'AAAA1111AAAA1111';
        $_COOKIE[TestContext::TEST_ID_COOKIE] = 'BBBB2222BBBB2222';

        self::assertSame('AAAA1111AAAA1111', TestContext::testId());
    }

    #[Test]
    public function ignoresAMalformedCookieTheSameWayAsAMalformedHeader(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $_COOKIE[TestContext::TEST_ID_COOKIE] = '../../etc/passwd';

        self::assertSame('', TestContext::testId());
    }

    #[Test]
    public function reportsNoMalformedTestIdWhenTheHeaderIsAbsent(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        self::assertNull(TestContext::malformedTestId());
    }

    /**
     * @return array<string, mixed>|null null when Environment was never initialized
     */
    private static function captureEnvironment(): ?array
    {
        try {
            return [
                'context' => Environment::getContext(),
                'cli' => Environment::isCli(),
                'composerMode' => Environment::isComposerMode(),
                'projectPath' => Environment::getProjectPath(),
                'publicPath' => Environment::getPublicPath(),
                'varPath' => Environment::getVarPath(),
                'configPath' => Environment::getConfigPath(),
                'currentScript' => Environment::getCurrentScript(),
                'os' => Environment::isWindows() ? 'WINDOWS' : 'UNIX',
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
