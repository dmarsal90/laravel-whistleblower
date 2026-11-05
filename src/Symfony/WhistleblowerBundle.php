<?php

declare(strict_types=1);

namespace Whistleblower\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Whistleblower\DeadlineChecker;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Port\ReportStore;
use Whistleblower\Retention;
use Whistleblower\Symfony\Command\ApplyRetentionCommand;
use Whistleblower\Symfony\Command\CheckDeadlinesCommand;
use Whistleblower\Symfony\Command\InstallSchemaCommand;
use Whistleblower\Symfony\Controller\ManageReportController;
use Whistleblower\Symfony\Controller\PublicReportController;
use Whistleblower\Symfony\Store\DbalReportStore;
use Whistleblower\Symfony\Store\SodiumCipher;
use Whistleblower\Whistleblower;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class WhistleblowerBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->info('Service id of the Doctrine DBAL connection.')->end()
            ->scalarNode('encryption_key')->defaultValue('%kernel.secret%')->info('Secret the personal fields are encrypted with. Losing or rotating it makes existing reports unreadable.')->end()
            ->scalarNode('ability')->defaultValue('WHISTLEBLOWER_MANAGE')->info('Security attribute required for the management endpoints. Grant it with a voter; nothing grants it by default.')->end()
            ->scalarNode('locale')->defaultValue('%kernel.default_locale%')->end()
            ->integerNode('throttle_per_minute')->defaultValue(10)->min(1)->end()
            ->arrayNode('categories')->scalarPrototype()->end()->defaultValue([])->info('Allowed categories. Empty means free text.')->end()
            ->arrayNode('notify')->scalarPrototype()->end()->defaultValue([])->info('E-mail addresses that receive deadline alerts (needs symfony/mailer).')->end()
            ->scalarNode('notify_from')->defaultNull()->end()
            ->arrayNode('deadlines')->addDefaultsIfNotSet()->children()
            ->integerNode('acknowledgement_days')->defaultValue(7)->end()
            ->integerNode('resolution_months')->defaultValue(3)->end()
            ->integerNode('extension_months')->defaultValue(3)->end()
            ->integerNode('warn_acknowledgement_days')->defaultValue(2)->end()
            ->integerNode('warn_resolution_days')->defaultValue(14)->end()
            ->end()->end()
            ->arrayNode('retention')->addDefaultsIfNotSet()->children()
            ->integerNode('uninvestigated_days')->defaultValue(90)->end()
            ->scalarNode('closed_content_days')->defaultNull()->end()
            ->integerNode('registry_years')->defaultValue(10)->end()
            ->end()->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array<string, int> $deadlines */
        $deadlines = $config['deadlines'];
        /** @var array<string, mixed> $retention */
        $retention = $config['retention'];
        $closed = $retention['closed_content_days'];

        $services = $container->services();

        $services->set('whistleblower.cipher', SodiumCipher::class)->args([$config['encryption_key']]);
        $services->set(DbalReportStore::class)->args([service($config['connection']), service('whistleblower.cipher')]);
        $services->alias(ReportStore::class, DbalReportStore::class);

        $services->set(Whistleblower::class)->public()->args([
            service(ReportStore::class),
            service('clock'),
            ['acknowledgement_days' => $deadlines['acknowledgement_days'], 'resolution_months' => $deadlines['resolution_months'], 'extension_months' => $deadlines['extension_months']],
        ]);
        $services->set(DeadlineChecker::class)->public()->args([
            service(ReportStore::class),
            service('clock'),
            ['warn_acknowledgement_days' => $deadlines['warn_acknowledgement_days'], 'warn_resolution_days' => $deadlines['warn_resolution_days']],
        ]);
        $services->set(Retention::class)->public()->args([
            service(ReportStore::class),
            service(Whistleblower::class),
            service('clock'),
            ['uninvestigated_days' => $retention['uninvestigated_days'], 'closed_content_days' => is_numeric($closed) ? (int) $closed : null, 'registry_years' => $retention['registry_years']],
        ]);

        $services->set('whistleblower.rate_limiter', RateLimiterFactory::class)->args([
            ['id' => 'whistleblower', 'policy' => 'fixed_window', 'limit' => $config['throttle_per_minute'], 'interval' => '1 minute'],
            inline_service(CacheStorage::class)->args([service('cache.app')]),
        ]);

        $services->set(PayloadValidator::class)->args([service('validator')]);
        $services->set(PublicReportController::class)->public()->tag('controller.service_arguments')->args([
            service(Whistleblower::class), service(PayloadValidator::class), service('whistleblower.rate_limiter'), $config['categories'], $config['locale'],
        ]);
        $services->set(ManageReportController::class)->public()->tag('controller.service_arguments')->args([
            service(Whistleblower::class), service(PayloadValidator::class), service('security.helper'), $config['ability'],
        ]);

        $services->set(CheckDeadlinesCommand::class)->tag('console.command')->args([service(DeadlineChecker::class), service('event_dispatcher')]);
        $services->set(ApplyRetentionCommand::class)->tag('console.command')->args([service(Retention::class)]);
        $services->set(InstallSchemaCommand::class)->tag('console.command')->args([service($config['connection'])]);

        if ($config['notify'] !== []) {
            $services->set(DeadlineAlertMailer::class)
                ->args([service(MailerInterface::class), $config['notify'], $config['notify_from'], $config['locale']])
                ->tag('kernel.event_listener', ['event' => DeadlineReached::class]);
        }
    }
}
