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
        $groups = [];
        foreach ($calls as $i => &$call) {
            $call['occurrences'] = 1;
            $call['curl'] = null;
            // Bulk/create payloads are deliberately summarized by the recorder. Comparing
            // those summaries would falsely flag different writes as identical requests.
            if ($call['operation'] !== 'search') {
                continue;
            }
            $key = hash('sha256', serialize([$call['connection'] ?? null, $call['index'], $call['body']]));
            $groups[$key][] = $i;
            $call['curl'] = $this->searchCurl($call);
        }
        unset($call);
        $duplicates = 0;
        foreach ($groups as $indices) {
            $count = count($indices);
            $duplicates += $count - 1;
            foreach ($indices as $i) {
                $calls[$i]['occurrences'] = $count;
            }
        }

        $this->data = [
            'calls' => $calls,
            'duplicates' => $duplicates,
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

    /** Number of repeated searches beyond the first request in each group. */
    public function getDuplicates(): int
    {
        return $this->data['duplicates'] ?? 0;
    }

    /** @param array<string, mixed> $call */
    private function searchCurl(array $call): ?string
    {
        $endpoint = $call['endpoint'] ?? null;
        if (!is_string($endpoint) || !is_array($parts = parse_url($endpoint))
            || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        // Rebuild instead of retaining userinfo, query-string credentials or fragments.
        $url = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($parts['path'] ?? '', '/') . '/' . rawurlencode($call['index']) . '/_search';
        $json = json_encode((object) $call['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        // POSIX shell quoting must escape apostrophes without evaluating $, backticks or newlines.
        $quote = static fn (string $value): string => "'" . str_replace("'", "'\\''", $value) . "'";

        return 'curl --globoff --request POST --url ' . $quote($url)
            . " --header 'Content-Type: application/json' --data-raw " . $quote($json);
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
