<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whistleblower\Symfony\Store\SchemaFactory;

#[AsCommand(name: 'whistleblower:schema:install', description: 'Create the whistleblower tables that do not exist yet, or print the SQL with --dump-sql.')]
final class InstallSchemaCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL instead of running it, to paste into a migration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $platform = $this->connection->getDatabasePlatform();
        $manager = $this->connection->createSchemaManager();
        $missing = array_filter(
            SchemaFactory::create()->getTables(),
            static fn ($table): bool => ! $manager->tablesExist([$table->getName()]),
        );

        if ($input->getOption('dump-sql')) {
            foreach ($missing as $table) {
                foreach ($platform->getCreateTableSQL($table) as $sql) {
                    $output->writeln($sql.';');
                }
            }

            return Command::SUCCESS;
        }

        foreach ($missing as $table) {
            $manager->createTable($table);
            $output->writeln('Created '.$table->getName().'.');
        }
        if ($missing === []) {
            $output->writeln('Nothing to do: all tables exist.');
        }

        return Command::SUCCESS;
    }
}
