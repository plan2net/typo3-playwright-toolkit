<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Command;

use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

final class PrepareCommand extends Command
{
    public function __construct(
        private readonly TemplatePreparer $preparer,
        private readonly TestApiSecret $secret,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription(
            'Builds the Playwright test database template that every per-test database is cloned from.'
        );
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Rebuild even when the stored fingerprint matches the current schema, fixtures and session seed.'
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!Environment::getContext()->isTesting()) {
            $io->error(sprintf(
                'This command only runs in a Testing context, not "%s". Set TYPO3_CONTEXT=Testing.',
                (string) Environment::getContext()
            ));

            return Command::FAILURE;
        }

        // Before the template: without a secret every endpoint refuses, and this is
        // the one command that always runs before a test run.
        $this->secret->ensureExists();

        $result = $this->preparer->prepare((bool) $input->getOption('force'));
        $io->success($result['built']
            ? 'Test database template prepared. Seed fingerprint: ' . $result['fingerprint']
            : 'Test database template already current, nothing rebuilt. Pass --force to rebuild anyway.');

        return Command::SUCCESS;
    }
}
