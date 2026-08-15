<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Profiler;

use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientDecoratorInterface;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/** Wraps each client the factory builds, all writing into one recorder. Debug only. */
final readonly class TracingClientDecorator implements ElasticsearchClientDecoratorInterface
{
    public function __construct(
        private ElasticCallRecorder $recorder,
        private ?Stopwatch $stopwatch = null,
    ) {}

    public function decorate(ElasticsearchClientInterface $client): ElasticsearchClientInterface
    {
        return new TraceableElasticsearchClient($client, $this->recorder, $this->stopwatch);
    }
}
