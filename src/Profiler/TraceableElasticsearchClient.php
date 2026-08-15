<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Profiler;

use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Records every Elasticsearch call into a shared recorder so the profiler can show it.
 *
 * This exists because elastic/transport uses its own HTTP client: Elasticsearch traffic never
 * reaches Symfony's HTTP Client panel or the log, so from inside the app a query that ran and a
 * query that never happened look identical. That is a poor way to debug a query language where
 * one wrong field name silently returns zero hits.
 */
final readonly class TraceableElasticsearchClient implements ElasticsearchClientInterface
{
    public function __construct(
        private ElasticsearchClientInterface $inner,
        private ElasticCallRecorder $recorder,
        private ?Stopwatch $stopwatch = null,
    ) {}

    public function search(string $index, array $body): array
    {
        return $this->record('search', $index, $body, fn (): array => $this->inner->search($index, $body));
    }

    public function createIndex(string $index, array $mappings): void
    {
        $this->record('createIndex', $index, ['mapped_fields' => count($mappings)], function () use ($index, $mappings): array {
            $this->inner->createIndex($index, $mappings);

            return [];
        });
    }

    public function deleteIndex(string $index): void
    {
        $this->record('deleteIndex', $index, [], function () use ($index): array {
            $this->inner->deleteIndex($index);

            return [];
        });
    }

    public function indexExists(string $index): bool
    {
        $exists = false;
        $this->record('indexExists', $index, [], function () use ($index, &$exists): array {
            $exists = $this->inner->indexExists($index);

            return ['exists' => $exists];
        });

        return $exists;
    }

    public function bulk(array $body): array
    {
        // Bulk payloads are enormous -- record the operation count, never the documents.
        return $this->record('bulk', '', ['operations' => intdiv(count($body), 2)], fn (): array => $this->inner->bulk($body));
    }

    public function refresh(string $index): void
    {
        $this->record('refresh', $index, [], function () use ($index): array {
            $this->inner->refresh($index);

            return [];
        });
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    /**
     * @param array<string, mixed> $body
     * @param callable(): array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function record(string $operation, string $index, array $body, callable $run): array
    {
        $event = $this->stopwatch?->start('elasticsearch.' . $operation, 'elasticsearch');
        $start = microtime(true);
        $response = [];
        $error = null;

        try {
            $response = $run();
        } catch (\Throwable $e) {
            // Record the failure too -- a rejected query is exactly what you opened the panel for.
            $error = $e->getMessage();
            throw $e;
        } finally {
            $event?->stop();
            $this->recorder->add([
                'operation' => $operation,
                'index' => $index,
                'body' => $body,
                'duration' => (microtime(true) - $start) * 1000,
                'hits' => $response['hits']['total']['value'] ?? null,
                'took' => $response['took'] ?? null,
                'aggregations' => array_keys($response['aggregations'] ?? []),
                'error' => $error,
            ]);
        }

        return $response;
    }
}
