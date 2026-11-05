<?php

declare(strict_types=1);

namespace Whistleblower\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use Whistleblower\Laravel\WhistleblowerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WhistleblowerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        if (getenv('DB_CONNECTION') === 'mysql') {
            $config->set('database.default', 'mysql');
            $config->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
        }
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
