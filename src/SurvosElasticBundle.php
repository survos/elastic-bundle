<?php

declare(strict_types=1);

namespace Survos\ElasticBundle;

use Survos\ElasticBundle\EventListener\ElasticSpoolDoctrineListener;
use Survos\ElasticBundle\MessageHandler\ReindexDocumentsHandler;
use Survos\ElasticBundle\MessageHandler\RemoveDocumentsHandler;
use Survos\ElasticBundle\Profiler\ElasticCallRecorder;
use Survos\ElasticBundle\Profiler\ElasticDataCollector;
use Survos\ElasticBundle\Profiler\TracingClientDecorator;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Survos\Kit\AbstractSurvosBundle;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Survos\Kit\SurvosKitBundle;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientDecoratorInterface;
use Survos\SearchBundle\SurvosSearchBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;

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
                    ->info('Turn the Doctrine listener off for bulk imports that reindex explicitly afterwards.')
                ->end()
                ->booleanNode('async')
                    ->defaultTrue()
                    ->info('Dispatch reindex work through Messenger. With this off (or with no bus installed) the listener writes a JSONL spool for elastic:spool:flush instead -- the right mode for bulk imports.')
                ->end()
                ->integerNode('batch_size')
                    ->defaultValue(500)
                    ->info('Ids per message. One huge flush becomes several bounded jobs.')
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

        // Messenger handlers. This bundle registers services explicitly rather than by
        // directory resource, so #[AsMessageHandler] alone would never be seen.
        if (interface_exists(MessageBusInterface::class)) {
            $services->set(ReindexDocumentsHandler::class);
            $services->set(RemoveDocumentsHandler::class);
        }

        // The listener needs Doctrine's event attribute; without doctrine/orm there is nothing
        // to listen to and the bundle is still useful for create/populate/status.
        if (class_exists(\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener::class)) {
            $services->set(ElasticSpoolDoctrineListener::class)
                ->arg('$enabled', $config['spool_enabled'])
                ->arg('$async', $config['async'] && interface_exists(MessageBusInterface::class))
                ->arg('$batchSize', $config['batch_size']);
        }

        // Profiler integration, debug only. The traceable client is the only way Elasticsearch
        // traffic becomes visible to Symfony at all -- elastic/transport bypasses the HTTP client.
        if ($builder->getParameter('kernel.debug') && class_exists(AbstractDataCollector::class)) {
            $services->set(ElasticCallRecorder::class);
            $services->set(TracingClientDecorator::class);
            // Explicit alias: autowiring will not bind an interface to an implementation on its
            // own, so without this search-bundle's factory receives a null decorator and the
            // panel silently reports zero calls.
            $services->alias(ElasticsearchClientDecoratorInterface::class, TracingClientDecorator::class);
            $services->set(ElasticDataCollector::class)
                ->tag('data_collector', [
                    'template' => '@SurvosElastic/data_collector/elastic.html.twig',
                    'id' => 'survos_elastic',
                ]);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [dirname(__DIR__).'/templates' => 'SurvosElastic'],
            ]);
        }
    }
}
