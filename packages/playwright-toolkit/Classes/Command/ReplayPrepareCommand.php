<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Command;

use Plan2net\PlaywrightToolkit\Database\ReplayPreparer;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

final class ReplayPrepareCommand extends Command
{
    public function __construct(
        private readonly ReplayPreparer $preparer,
        private readonly TestApiSecret $secret,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription(
            'Rebuilds the testing site\'s own database from the template, so every scenario can replay into it.'
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

        $this->secret->ensureExists();

        try {
            $database = $this->preparer->prepare();
        } catch (\RuntimeException|\InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Database "%s" is a fresh clone of the template. Replay can run.', $database));

        return Command::SUCCESS;
    }
}
