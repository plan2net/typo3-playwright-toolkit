<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Command;

use Composer\InstalledVersions;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Database\TemplateReadiness;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Setup\Answers;
use Plan2net\PlaywrightToolkit\Setup\Check\AdditionalConfiguration;
use Plan2net\PlaywrightToolkit\Setup\Check\Addon;
use Plan2net\PlaywrightToolkit\Setup\Check\BrowsersPath;
use Plan2net\PlaywrightToolkit\Setup\Check\Fixtures;
use Plan2net\PlaywrightToolkit\Setup\Check\NpmPackage;
use Plan2net\PlaywrightToolkit\Setup\Check\PlaywrightConfig;
use Plan2net\PlaywrightToolkit\Setup\Check\SpecFile;
use Plan2net\PlaywrightToolkit\Setup\Check\TestingHost;
use Plan2net\PlaywrightToolkit\Setup\Closing;
use Plan2net\PlaywrightToolkit\Setup\DdevHostname;
use Plan2net\PlaywrightToolkit\Setup\FileWriter;
use Plan2net\PlaywrightToolkit\Setup\HostCommands;
use Plan2net\PlaywrightToolkit\Setup\PrepareRun;
use Plan2net\PlaywrightToolkit\Setup\Result;
use Plan2net\PlaywrightToolkit\Setup\RunLocation;
use Plan2net\PlaywrightToolkit\Setup\WebserverHint;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SetupCommand extends Command
{
    /**
     * @var string
     */
    private const ADDON_COMMAND = '/mnt/ddev_config/commands/web/playwright';

    /**
     * @var int
     */
    private const RESULT_WIDTH = 60;

    /**
     * @var string
     */
    private const ADDITIONAL_CONFIGURATION_SNIPPET = <<<'PHP'
        if (\TYPO3\CMS\Core\Core\Environment::getContext()->isTesting()) {
            \Plan2net\PlaywrightToolkit\TestContext::configureCurrentRequest();
        }
        PHP;

    public function __construct(
        private readonly TestApiSecret $secret,
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly SiteFinder $siteFinder,
        private readonly TemplateReadiness $readiness,
        private readonly ClientInterface $client,
        private readonly ?string $installedVersion = null,
        private readonly ?string $addonCommand = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Sets up this project for Playwright tests, or diagnoses a setup that already exists.');
        $this->setHelp(
            'Runs every check from the setup guide, writes the files you are missing, and prints the '
            . 'commands you have to run yourself. With --no-interaction it changes nothing and only '
            . 'reports, so you can use it to check a project that is already set up.'
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (false === getenv('DDEV_SITENAME')) {
            $io->error(
                'DDEV_SITENAME is not set, and this command sets up the DDEV route. '
                . 'Without DDEV, follow "Without DDEV" in the extension README.'
            );

            return Command::FAILURE;
        }

        $io->writeln(['', 'Playwright Setup', '']);

        // Check 1 needs it, and playwright:prepare would write the same file anyway.
        $secret = $this->secret->ensureExists();

        $configured = getenv('PW_TEST_DIR');
        $testDirectory = (string) $io->ask(
            'Test directory',
            false === $configured || '' === $configured ? Answers::DEFAULT_TEST_DIRECTORY : $configured,
            self::refusing(Answers::testDirectoryProblem(...))
        );

        $testingUrl = (string) $io->ask(
            'Testing URL',
            sprintf(
                'https://%s-testing.%s',
                (string) getenv('DDEV_SITENAME'),
                (string) getenv('DDEV_TLD')
            ),
            self::refusing(Answers::testingUrlProblem(...))
        );

        $results = $this->runChecks($testDirectory, $testingUrl, $secret);
        $this->report($io, $results);

        // Write, then print, then run, so a printed command is not lost above a build.
        $changed = $this->write($io, $results, $testDirectory, $testingUrl);
        if ($changed) {
            // The build needs the fixtures that were just written, so it judges the new state.
            $results = $this->runChecks($testDirectory, $testingUrl, $secret);
        }

        $printedHostCommands = $this->print($io, $results, $testDirectory, $testingUrl);
        $changed = $this->buildTemplate($io, $results, $input->isInteractive()) || $changed;

        if ($changed) {
            // The run that changed something would otherwise end on the old table.
            $results = $this->runChecks($testDirectory, $testingUrl, $secret);
            $this->report($io, $results);
        }

        $everythingPasses = $this->passed($results);
        $closing = Closing::line($everythingPasses, $printedHostCommands);

        if ('' === $closing) {
            // The blocks above end on one; without a block the summary would sit
            // flush against the next shell prompt.
            $io->newLine();
        } else {
            $everythingPasses ? $io->success($closing) : $io->warning($closing);
        }

        return $everythingPasses ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function write(SymfonyStyle $io, array $results, string $testDirectory, string $testingUrl): bool
    {
        // Check 6 names files under the configured fixtures path, the others under the
        // test directory. Writing them all to one place is how step 6 never closes.
        $fixtures = self::fixturesPath(
            Environment::getProjectPath(),
            $this->configurationFactory->create()
        );
        $bases = [];
        foreach ($results as $row) {
            foreach ($row['result']->missingFiles as $file) {
                $bases[$file] = 6 === $row['step'] ? $fixtures : Environment::getProjectPath() . '/' . $testDirectory;
            }
        }
        if ([] === $bases) {
            return false;
        }

        $io->section('Files to write');
        $io->listing(array_map(
            static fn(string $file, string $base): string => $base . '/' . $file,
            array_keys($bases),
            array_values($bases)
        ));
        if (!$io->confirm('Write them?', false)) {
            return false;
        }

        $placeholders = [
            'TESTING_URL' => $testingUrl,
            'PROJECT_ROOT' => Answers::relativeProjectRoot($testDirectory),
            'ROOT_PAGE_ID' => (string) ($this->rootPageId() ?? 1),
        ];
        foreach ($bases as $file => $base) {
            try {
                $writer = new FileWriter($base, $placeholders, Environment::getProjectPath());
                $io->writeln('Wrote ' . $writer->write($file));
            } catch (\InvalidArgumentException $refused) {
                $io->warning($refused->getMessage() . ' Write it yourself, in ' . $base . '.');
            }
        }

        return true;
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function buildTemplate(SymfonyStyle $io, array $results, bool $interactive): bool
    {
        // It needs the database service and the fixtures, and nothing else.
        if ($this->passes($results, 9) || !$this->passes($results, 2) || !$this->passes($results, 6)) {
            return false;
        }

        // Without a terminal a confirm prints nothing, so the heading would stand alone.
        if (!$interactive) {
            return false;
        }

        $io->section('The test database template');
        if (!$io->confirm('Build it now?', false)) {
            return false;
        }

        foreach (PrepareRun::commands(Environment::getProjectPath()) as $command) {
            $io->writeln('Running ' . $command);
            passthru($command, $status);
            if (0 !== $status) {
                $io->error('That command failed, so the template is not built.');

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function passes(array $results, int $step): bool
    {
        foreach ($results as $row) {
            if ($step === $row['step']) {
                return $row['result']->passed;
            }
        }

        return false;
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function print(SymfonyStyle $io, array $results, string $testDirectory, string $testingUrl): bool
    {
        $failed = static function (array $results, int $step): bool {
            foreach ($results as $row) {
                if ($step === $row['step']) {
                    return !$row['result']->passed;
                }
            }

            return false;
        };

        foreach ($results as $row) {
            if (1 === $row['step'] && str_contains($row['result']->detail, TestingHost::WRONG_CONTEXT)) {
                $io->section('Your web server decides the context');
                $io->writeln(WebserverHint::forWebserver(
                    (string) getenv('DDEV_WEBSERVER_TYPE'),
                    Environment::getProjectPath()
                ));
            }
        }

        if ($failed($results, 5)) {
            $io->section('Add this to ' . GeneralUtility::makeInstance(ConfigurationManager::class)
                ->getAdditionalConfigurationFileLocation());
            $io->writeln(self::ADDITIONAL_CONFIGURATION_SNIPPET);
        }

        $testDirectoryConfigured = getenv('PW_TEST_DIR') === $testDirectory;
        $needsHostCommands = $failed($results, 1) || $failed($results, 2) || $failed($results, 3)
            || $failed($results, 4)
            || (!$testDirectoryConfigured && Answers::DEFAULT_TEST_DIRECTORY !== $testDirectory);
        if (!$needsHostCommands) {
            return false;
        }

        $block = HostCommands::block(
            DdevHostname::flagFor(Environment::getProjectPath(), $testingUrl, (string) getenv('DDEV_TLD')),
            $failed($results, 4),
            $testDirectory,
            $failed($results, 3),
            $this->installedVersion ?? InstalledVersions::getPrettyVersion('plan2net/playwright-toolkit'),
            is_file(Environment::getProjectPath() . '/' . $testDirectory . '/package.json'),
            !is_file($this->addonCommand ?? self::ADDON_COMMAND),
            $testDirectoryConfigured
        );
        if ([] === $block) {
            return false;
        }

        $io->section('Run these yourself');
        $io->writeln($block);

        return true;
    }

    /**
     * @return list<array{step: int, checked: string, result: Result}>
     */
    private function runChecks(string $testDirectory, string $testingUrl, string $secret): array
    {
        $projectPath = Environment::getProjectPath();
        $directory = $projectPath . '/' . $testDirectory;
        $configuration = $this->configurationFactory->create();
        $version = $this->installedVersion ?? InstalledVersions::getPrettyVersion('plan2net/playwright-toolkit');
        $runsElsewhere = RunLocation::inAnotherContainer(getenv(TestApiSecret::ENV_NAME) ?: null, $directory);

        return [
            [
                'step' => 1,
                'checked' => 'the testing hostname, in a Testing context',
                'result' => (new TestingHost($testingUrl, $secret, $this->client))->run(),
            ],
            [
                'step' => 2,
                'checked' => 'the DDEV add-on and its database service',
                'result' => (new Addon(
                    $this->addonCommand ?? self::ADDON_COMMAND,
                    $projectPath,
                    $version,
                    $this->probeTestDatabase(),
                    $runsElsewhere
                ))->run(),
            ],
            [
                'step' => 3,
                'checked' => 'the npm package beside your tests',
                'result' => (new NpmPackage($directory, $version, $runsElsewhere))->run(),
            ],
            [
                'step' => 4,
                'checked' => 'the browsers',
                'result' => (new BrowsersPath(
                    getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null,
                    getenv('PW_TEST_CONNECT_WS_ENDPOINT') ?: null,
                    $runsElsewhere,
                    $projectPath
                ))->run(),
            ],
            [
                'step' => 5,
                'checked' => 'the additional configuration file',
                'result' => (new AdditionalConfiguration(
                    GeneralUtility::makeInstance(ConfigurationManager::class)
                        ->getAdditionalConfigurationFileLocation()
                ))->run(),
            ],
            [
                'step' => 6,
                'checked' => 'the fixtures and the site root page',
                'result' => (new Fixtures(
                    self::fixturesPath($projectPath, $configuration),
                    $configuration->fixtureManifest,
                    $this->rootPageId()
                ))->run(),
            ],
            [
                'step' => 7,
                'checked' => 'the Playwright configuration',
                'result' => (new PlaywrightConfig($directory, $testingUrl))->run(),
            ],
            [
                'step' => 8,
                'checked' => 'your first scenario',
                'result' => (new SpecFile($directory))->run(),
            ],
            [
                'step' => 9,
                'checked' => 'the test database template',
                'result' => $this->templateResult($configuration),
            ],
        ];
    }

    private function templateResult(ToolkitConfiguration $configuration): Result
    {
        $driver = TestDatabaseDriverFactory::fromConnectionOrNull(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? null
        );
        if (null === $driver) {
            return Result::fail('the Default connection names no test database service');
        }

        $readiness = $this->readiness;

        return Result::of(static function () use ($readiness, $driver, $configuration): string {
            $readiness->assertPrepared($driver, $configuration);

            return 'prepared, and its fingerprint is current';
        });
    }

    /**
     * @return \Closure(): ?string
     */
    private function probeTestDatabase(): \Closure
    {
        $engine = TestDatabaseDriverFactory::fromConnectionOrNull(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? null
        )?->engine() ?? Engine::Mysql;

        // A sqlite test database is a file, so there is no service to reach.
        if (Engine::Sqlite === $engine) {
            return static fn(): ?string => null;
        }

        return Addon::probeFor(TestDatabaseService::fromEnvironment($engine));
    }

    private static function fixturesPath(string $projectPath, ToolkitConfiguration $configuration): string
    {
        $configured = ltrim($configuration->fixturesPath, '/');

        return '' === $configured ? '' : $projectPath . '/' . $configured;
    }

    private function rootPageId(): ?int
    {
        $sites = $this->siteFinder->getAllSites();
        $site = reset($sites);

        return false === $site ? null : $site->getRootPageId();
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function report(SymfonyStyle $io, array $results): void
    {
        $table = $io->createTable();
        $table->setStyle('box');
        $table->setHeaders(['Step', 'Checked', 'Result']);
        // A detail can be a long path or a cURL message; wrap rather than run off the terminal.
        $table->setColumnMaxWidth(2, self::RESULT_WIDTH);
        $rows = [];
        foreach ($results as $row) {
            if ([] !== $rows) {
                $rows[] = new TableSeparator();
            }

            $rows[] = [
                (string) $row['step'],
                $row['checked'],
                ($row['result']->passed ? 'ok, ' : 'no, ') . $row['result']->detail,
            ];
        }
        $table->setRows($rows);
        $table->render();
        $io->newLine();

        $passing = \count(array_filter($results, static fn(array $row): bool => $row['result']->passed));
        $io->writeln(sprintf(
            '%d of %d checks pass, read in the %s context.',
            $passing,
            \count($results),
            (string) Environment::getContext()
        ));

        if (!Environment::getContext()->isTesting()) {
            $io->writeln(
                'Settings behind an isTesting() check are not visible here. Run '
                . '`TYPO3_CONTEXT=Testing typo3 playwright:setup` in the container, '
                . 'or `ddev playwright setup` from your host.'
            );
        }
    }

    /**
     * @param list<array{step: int, checked: string, result: Result}> $results
     */
    private function passed(array $results): bool
    {
        foreach ($results as $row) {
            if (!$row['result']->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(string): ?string $problem
     */
    private static function refusing(callable $problem): \Closure
    {
        return static function (?string $answer) use ($problem): string {
            $reason = $problem((string) $answer);
            if (null !== $reason) {
                throw new \InvalidArgumentException($reason, 1756900000);
            }

            return (string) $answer;
        };
    }
}
