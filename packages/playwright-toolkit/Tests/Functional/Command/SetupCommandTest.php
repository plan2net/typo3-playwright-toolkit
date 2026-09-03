<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Command\SetupCommand;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\TemplateReadiness;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SetupCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DDEV_SITENAME', 'DDEV_TLD', 'DDEV_WEBSERVER_TYPE', 'PW_TEST_DIR'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name . '=' . $value);
        }

        parent::tearDown();
    }

    #[Test]
    public function offersTheDefaultTestDirectory(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '']);
        $tester->execute([]);

        self::assertStringContainsString(
            '[tests/playwright]',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function offersTheConfiguredTestDirectory(): void
    {
        putenv('DDEV_SITENAME=example');
        putenv('PW_TEST_DIR=tests/e2e');

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '']);
        $tester->execute([]);

        self::assertStringContainsString(
            '[tests/e2e]',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function offersTheProjectsTestingHostname(): void
    {
        putenv('DDEV_SITENAME=example');
        putenv('DDEV_TLD=ddev.site');

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '']);
        $tester->execute([]);

        self::assertStringContainsString(
            '[https://example-testing.ddev.site]',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function asksAgainAfterATestDirectoryItRefuses(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->setInputs(['../outside', 'tests/e2e', '']);
        $tester->execute([]);

        self::assertStringContainsString(
            '..',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function asksAgainAfterATestingUrlItRefuses(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', 'https://example.test/subdir', 'https://example.test']);
        $tester->execute([]);

        self::assertStringContainsString(
            'bare origin',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function reportsTheAdditionalConfigurationFile(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([], ['interactive' => false]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('additional configuration file', $display);
    }

    // A project may apply the toolkit settings for the Testing context only.
    #[Test]
    public function namesTheContextItRead(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString(
            'read in the ' . Environment::getContext() . ' context',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function saysWhatAnotherContextHidesFromIt(): void
    {
        putenv('DDEV_SITENAME=example');
        $originalContext = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext('Development'));

        try {
            $tester = new CommandTester($this->command());
            $tester->execute([], ['interactive' => false]);

            self::assertStringContainsString(
                'Settings behind an isTesting() check are not visible here',
                (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
            );
        } finally {
            $this->reinitializeWith($originalContext);
        }
    }

    /**
     * The add-on's shorthand needs the add-on, and inside the web container there is no
     * `ddev` at all, so the hint has to name the command that works everywhere.
     */
    #[Test]
    public function namesACommandThatWorksWithoutTheAddon(): void
    {
        putenv('DDEV_SITENAME=example');
        $originalContext = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext('Development'));

        try {
            $tester = new CommandTester($this->command());
            $tester->execute([], ['interactive' => false]);

            self::assertStringContainsString(
                '`TYPO3_CONTEXT=Testing typo3 playwright:setup` in the container, '
                . 'or `ddev playwright setup` from your host',
                (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
            );
        } finally {
            $this->reinitializeWith($originalContext);
        }
    }

    // With no closing block, the summary sat flush against the next shell prompt.
    #[Test]
    public function endsOnABlankLine(): void
    {
        putenv('DDEV_SITENAME=example');
        putenv('PW_TEST_CONNECT_WS_ENDPOINT=ws://playwright-server:3000/');
        $directory = $this->instancePath . '/tests/playwright';
        $package = $directory . '/node_modules/@plan2net/typo3-playwright-toolkit';
        if (!is_dir($package)) {
            mkdir($package, 0777, true);
        }
        file_put_contents($package . '/package.json', json_encode(['version' => '1.0.0']));
        file_put_contents($directory . '/package.json', json_encode(['type' => 'module']));

        $healthy = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new JsonResponse([
                    'api' => TestContext::API_VERSION,
                    'checks' => ['context' => ['ok' => true]],
                ], 503);
            }
        };

        $tester = new CommandTester($this->command($healthy, $this->addonCommandFile()));
        $tester->execute([], ['interactive' => false]);

        self::assertStringEndsWith("\n\n", $tester->getDisplay());
    }

    #[Test]
    public function printsAPlainHeading(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString("\nPlaywright Setup\n", $tester->getDisplay());
    }

    // The detail can be a long path or a cURL message; a terminal is about 120 columns.
    #[Test]
    public function keepsTheTableWithinATerminalWidth(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        // The table only: a path in a listing is as long as the path, and wraps.
        $tableLines = array_filter(
            explode("\n", $tester->getDisplay()),
            static fn(string $line): bool => 1 === preg_match('/^[┌│├└]/u', $line)
        );

        self::assertNotSame([], $tableLines);
        self::assertLessThanOrEqual(120, max([0, ...array_map('mb_strlen', $tableLines)]));
    }

    #[Test]
    public function drawsALineBetweenTheRows(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        // One below the header, and one between every pair of the nine checks.
        self::assertSame(9, substr_count($tester->getDisplay(), '├'));
    }

    #[Test]
    public function drawsTheTableWithBoxCorners(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('┌', $tester->getDisplay());
        self::assertStringContainsString('┼', $tester->getDisplay());
    }

    #[Test]
    public function reportsEveryStepOfTheGuide(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([], ['interactive' => false]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $exitCode);
        foreach ([
            'the testing hostname',
            'the DDEV add-on',
            'the npm package',
            'the browsers',
            'the additional configuration file',
            'the fixtures',
            'the Playwright configuration',
            'your first scenario',
            'the test database template',
        ] as $checked) {
            self::assertStringContainsString($checked, $display);
        }
        self::assertStringContainsString('of 9 checks', $display);
    }

    #[Test]
    public function writesTheMissingFilesAndChecksAgain(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        // The last answer refuses the template build, which would run a subprocess.
        $tester->setInputs(['', '', 'yes', 'no']);
        $tester->execute([]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertFileExists($this->instancePath . '/tests/playwright/tsconfig.json');
        self::assertFileExists($this->instancePath . '/tests/playwright/tests/first.spec.ts');
        self::assertSame(2, substr_count($display, 'of 9 checks'));
    }

    #[Test]
    public function writesAFixtureIntoTheConfiguredFixturesPath(): void
    {
        putenv('DDEV_SITENAME=example');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'tests/playwright/fixtures',
            'fixtureManifest' => '010-root-page.sql',
        ];

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '', 'yes', 'no']);
        $tester->execute([]);

        self::assertFileExists($this->instancePath . '/tests/playwright/fixtures/010-root-page.sql');
    }

    #[Test]
    public function writesNothingOutsideTheProject(): void
    {
        putenv('DDEV_SITENAME=example');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '../../escaped-fixtures',
            'fixtureManifest' => '010-root-page.sql',
        ];

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '', 'yes', 'no']);
        $tester->execute([]);

        self::assertStringContainsString('outside', $tester->getDisplay());
        self::assertFileDoesNotExist(\dirname($this->instancePath, 2) . '/escaped-fixtures/010-root-page.sql');
    }

    #[Test]
    public function saysWhichFixtureItCannotWriteForYou(): void
    {
        putenv('DDEV_SITENAME=example');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'tests/playwright/fixtures',
            'fixtureManifest' => '010-root-page.sql, 020-content.sql',
        ];

        $tester = new CommandTester($this->command());
        $tester->setInputs(['', '', 'yes', 'no']);
        $tester->execute([]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertFileExists($this->instancePath . '/tests/playwright/fixtures/010-root-page.sql');
        self::assertFileDoesNotExist($this->instancePath . '/tests/playwright/fixtures/020-content.sql');
        self::assertStringContainsString('020-content.sql', $display);
    }

    #[Test]
    public function printsHowToInstallTheAddonWhenItIsMissing(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command(addonCommand: $this->instancePath . '/absent'));
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('ddev add-on get', $tester->getDisplay());
    }

    #[Test]
    public function printsWhatOnlyTheUserCanRun(): void
    {
        putenv('DDEV_SITENAME=example');
        putenv('DDEV_TLD=ddev.site');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('ddev config --additional-hostnames=example-testing', $display);
        self::assertStringContainsString('TestContext::configureCurrentRequest()', $display);
        self::assertStringContainsString('Stopped here', $display);
    }

    #[Test]
    public function claimsNothingIsReadyWhileChecksFail(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringNotContainsString('Ready.', $tester->getDisplay());
    }

    #[Test]
    public function offersNoBuildWithoutATerminalToAnswerIt(): void
    {
        putenv('DDEV_SITENAME=example');

        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        self::assertStringNotContainsString('The test database template', $tester->getDisplay());
    }

    #[Test]
    public function offersTheBuildAfterWritingWhatItWasWaitingFor(): void
    {
        putenv('DDEV_SITENAME=example');
        $this->configureSite(1);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'tests/playwright/fixtures',
            'fixtureManifest' => '010-root-page.sql',
        ];

        $tester = new CommandTester($this->command(addonCommand: $this->addonCommandFile()));
        $tester->setInputs(['', '', 'yes', 'no']);
        $tester->execute([]);

        self::assertStringContainsString('Build it now?', $tester->getDisplay());
    }

    #[Test]
    public function offersToBuildTheTemplateOnceTheServiceAndFixturesAreThere(): void
    {
        putenv('DDEV_SITENAME=example');
        $this->configureSite(1);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'tests/playwright/fixtures',
            'fixtureManifest' => '010-root-page.sql',
        ];
        if (!is_dir($this->instancePath . '/tests/playwright/fixtures')) {
            mkdir($this->instancePath . '/tests/playwright/fixtures', 0777, true);
        }
        file_put_contents(
            $this->instancePath . '/tests/playwright/fixtures/010-root-page.sql',
            "INSERT INTO pages (uid, pid) VALUES (1, 0);\n"
        );

        $tester = new CommandTester($this->command(addonCommand: $this->addonCommandFile()));
        $tester->setInputs(['', '', 'no', 'no']);
        $tester->execute([]);

        self::assertStringContainsString('The test database template', $tester->getDisplay());
        self::assertStringContainsString('Build it now?', $tester->getDisplay());
    }

    #[Test]
    public function stopsWhenTheProjectDoesNotRunDdev(): void
    {
        putenv('DDEV_SITENAME');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Without DDEV',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    #[Test]
    public function saysWhyTheWebserverServesAnotherContext(): void
    {
        putenv('DDEV_SITENAME=example');
        // A runner has no DDEV, so the webserver the hint speaks about is named here.
        putenv('DDEV_WEBSERVER_TYPE=nginx-fpm');

        $answering = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new JsonResponse([], 404);
            }
        };

        $tester = new CommandTester($this->command($answering));
        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('TYPO3_CONTEXT', $tester->getDisplay());
    }

    /**
     * Check 6 compares the fixture's uid against a site's rootPageId, so without a
     * site configuration there is nothing for it to agree with.
     */
    private function configureSite(int $rootPageId): void
    {
        $site = Environment::getConfigPath() . '/sites/main';
        mkdir($site, 0777, true);
        file_put_contents($site . '/config.yaml', <<<YAML
            rootPageId: {$rootPageId}
            base: 'https://example-testing.ddev.site/'
            languages:
              -
                languageId: 0
                title: English
                base: /
                locale: en_US.UTF-8
            YAML);
    }

    /**
     * Check 2 looks for the add-on's own command file, which only a DDEV project has.
     */
    private function addonCommandFile(): string
    {
        $file = $this->instancePath . '/playwright-addon-command';
        file_put_contents($file, "#!/usr/bin/env bash\n");

        return $file;
    }

    private function reinitializeWith(ApplicationContext $context): void
    {
        Environment::initialize(
            $context,
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );
    }

    private function command(?ClientInterface $client = null, ?string $addonCommand = null): SetupCommand
    {
        $offline = $client ?? new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class extends \RuntimeException implements ClientExceptionInterface {
                };
            }
        };

        return new SetupCommand(
            $this->get(TestApiSecret::class),
            $this->get(ToolkitConfigurationFactory::class),
            $this->get(SiteFinder::class),
            $this->get(TemplateReadiness::class),
            $offline,
            '1.0.0',
            $addonCommand
        );
    }
}
