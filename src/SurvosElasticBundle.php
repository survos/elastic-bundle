<?php

declare(strict_types=1);

namespace Survos\ElasticBundle;

use Survos\ElasticBundle\EventListener\ElasticSpoolDoctrineListener;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\SearchBundle\SurvosSearchBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosSearchBundle::class)]
final class SurvosElasticBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('spool_dir')
                    ->defaultValue('%kernel.project_dir%/var/elastic-spool')
                    ->info('Where postFlush writes the ids awaiting reindex.')
                ->end()
                ->booleanNode('spool_enabled')
                    ->defaultTrue()
                    ->info('Turn the Doctrine spool listener off for bulk imports that reindex explicitly afterwards.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(ElasticSpooler::class)
            ->arg('$spoolDir', $config['spool_dir'])
            ->public();

        $services->set(ElasticIndexService::class)->public();

        // The listener needs Doctrine's event attribute; without doctrine/orm there is nothing
        // to listen to and the bundle is still useful for create/populate/status.
        if (class_exists(\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener::class)) {
            $services->set(ElasticSpoolDoctrineListener::class)
                ->arg('$enabled', $config['spool_enabled']);
        }
    }
}
