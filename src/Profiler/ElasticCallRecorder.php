<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Profiler;

/**
 * Shared buffer of Elasticsearch calls for one request.
 *
 * Separate from the traceable client because a factory may build several clients (one per DSN)
 * while the profiler needs a single place to read from. The container holds one recorder; every
 * decorated client writes into it.
 */
final class ElasticCallRecorder
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @param array<string, mixed> $call */
    public function add(array $call): void
    {
        $this->calls[] = $call;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->calls;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    public function totalDuration(): float
    {
        return array_sum(array_column($this->calls, 'duration'));
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
