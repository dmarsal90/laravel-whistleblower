<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Whistleblower\Retention;

#[AsCommand(name: 'whistleblower:apply-retention', description: 'Anonymise reports past their retention period and delete expired registry entries. Schedule it daily.')]
final class ApplyRetentionCommand extends Command
{
    public function __construct(private readonly Retention $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->retention->apply();
        $output->writeln("{$result['anonymised']} report(s) anonymised, {$result['deleted']} registry entr(y/ies) deleted.");

        return Command::SUCCESS;
    }
}
