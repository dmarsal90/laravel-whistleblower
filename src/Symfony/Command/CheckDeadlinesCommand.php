<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Whistleblower\DeadlineChecker;

#[AsCommand(name: 'whistleblower:check-deadlines', description: 'Dispatch an event for every open report whose legal deadline is near or past. Schedule it daily.')]
final class CheckDeadlinesCommand extends Command
{
    public function __construct(private readonly DeadlineChecker $checker, private readonly EventDispatcherInterface $events)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $found = $this->checker->check();
        foreach ($found as $event) {
            $this->events->dispatch($event);
        }
        $output->writeln(count($found).' deadline alert(s) dispatched.');

        return Command::SUCCESS;
    }
}
