<?php

declare(strict_types=1);

namespace Whistleblower\Tests\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Whistleblower\Symfony\Store\SchemaFactory;
use Whistleblower\Whistleblower;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function boot(string $environment = 'test'): void
    {
        Clock::set(new MockClock('2026-01-01 09:00:00'));
        $this->client = static::createClient(['environment' => $environment]);
        // The in-memory database and the mock clock live in this kernel, so it must not reboot.
        $this->client->disableReboot();

        // Rate-limit counters live in the app cache, which is a filesystem pool shared across tests.
        static::getContainer()->get('cache.app')->clear();

        // The Laravel suite may have left its own tables, with foreign keys, in a shared MySQL database.
        $connection = $this->connection();
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $mysql && $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $manager = $connection->createSchemaManager();
        $tables = SchemaFactory::create()->getTables();
        foreach ($tables as $table) {
            if ($manager->tablesExist([$table->getName()])) {
                $manager->dropTable($table->getName());
            }
        }
        foreach ($tables as $table) {
            $manager->createTable($table);
        }
        $mysql && $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock);
        parent::tearDown();
    }

    protected function wb(): Whistleblower
    {
        /** @var Whistleblower */
        return static::getContainer()->get(Whistleblower::class);
    }

    protected function connection(): Connection
    {
        /** @var Connection */
        return static::getContainer()->get('test.connection');
    }

    protected function console(string $name): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find($name));
    }

    protected function time(string $time): void
    {
        Clock::set(new MockClock($time));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(string $method, string $uri, array $data = [], ?string $as = null): array
    {
        if ($as !== null) {
            $this->client->loginUser(new InMemoryUser($as, 'secret', ['ROLE_USER']));
        }
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (array) json_decode($content, true);
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
