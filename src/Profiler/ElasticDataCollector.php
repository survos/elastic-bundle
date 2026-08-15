<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ElasticDataCollector extends AbstractDataCollector
{
    public function __construct(private readonly ElasticCallRecorder $recorder) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $calls = $this->recorder->all();

        $this->data = [
            'calls' => $calls,
            'count' => count($calls),
            'duration' => $this->recorder->totalDuration(),
            'errors' => count(array_filter($calls, static fn (array $c): bool => $c['error'] !== null)),
            // A search returning zero hits is the single most common Elasticsearch mistake --
            // a mistyped field or a facet filtered on the wrong subfield fails silently.
            // Surface it in the toolbar rather than making someone open the panel to notice.
            'empty' => count(array_filter(
                $calls,
                static fn (array $c): bool => $c['operation'] === 'search' && $c['hits'] === 0,
            )),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    public function getCount(): int
    {
        return $this->data['count'] ?? 0;
    }

    public function getDuration(): float
    {
        return $this->data['duration'] ?? 0.0;
    }

    public function getErrors(): int
    {
        return $this->data['errors'] ?? 0;
    }

    public function getEmpty(): int
    {
        return $this->data['empty'] ?? 0;
    }

    public function reset(): void
    {
        $this->data = [];
        $this->recorder->reset();
    }

    public static function getTemplate(): ?string
    {
        return '@SurvosElastic/data_collector/elastic.html.twig';
    }

    public function getName(): string
    {
        return 'survos_elastic';
    }
}
