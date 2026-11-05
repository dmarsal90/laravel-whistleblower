<?php

declare(strict_types=1);

namespace Whistleblower\Tests\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Whistleblower\Symfony\WhistleblowerBundle;

/** The environment name selects extra options: test_categories, test_throttle, test_notify. */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new SecurityBundle;
        yield new WhistleblowerBundle;
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/whistleblower-kernel/'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/whistleblower-kernel/'.$this->environment.'/log';
    }

    public static function connection(): Connection
    {
        if (getenv('DB_CONNECTION') === 'mysql') {
            return DriverManager::getConnection([
                'driver' => 'pdo_mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'dbname' => getenv('DB_DATABASE') ?: 'testing',
                'user' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ]);
        }

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'cache' => ['app' => 'cache.adapter.filesystem'],
            'mailer' => ['dsn' => 'null://null'],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => ['manager' => ['password' => 'secret', 'roles' => ['ROLE_USER']], 'visitor' => ['password' => 'secret', 'roles' => ['ROLE_USER']]]]]],
            'firewalls' => ['main' => ['provider' => 'users']],
        ]);
        $container->extension('whistleblower', [
            'connection' => 'test.connection',
            'categories' => str_contains($this->environment, 'categories') ? ['fraud', 'harassment'] : [],
            'throttle_per_minute' => str_contains($this->environment, 'throttle') ? 2 : 1000,
            'notify' => str_contains($this->environment, 'notify') ? ['dpo@example.com'] : [],
            'notify_from' => 'canal@example.com',
        ]);

        $services = $container->services();
        $services->set('test.connection', Connection::class)->public()->factory([self::class, 'connection']);
        $services->set(ManagerVoter::class)->tag('security.voter');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@WhistleblowerBundle/Controller/', 'attribute')->prefix('/whistleblower');
    }
}
