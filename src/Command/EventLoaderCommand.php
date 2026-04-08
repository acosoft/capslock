<?php
declare(strict_types=1);

namespace App\Command;

use App\Contract\EventLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EventLoaderCommand extends Command
{
    protected static $defaultName = 'app:event-loader';

    private EventLoaderInterface $loader;

    public function __construct(EventLoaderInterface $loader)
    {
        parent::__construct();
        $this->loader = $loader;
    }

    protected function configure(): void
    {
        $this->setDescription('Run the event loader');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single iteration and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loaderName = getenv('LOADER_NAME') ?: 'L?';

        if ($input->getOption('once')) {
            $this->loader->runOnce();
            $output->writeln(sprintf('%s: Run once completed.', $loaderName));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('%s: Starting event loader loop (CTRL+C to stop)', $loaderName));
        $this->loader->runLoop();
        $output->writeln(sprintf('%s: Event loader stopped.', $loaderName));
        return Command::SUCCESS;
    }
}
