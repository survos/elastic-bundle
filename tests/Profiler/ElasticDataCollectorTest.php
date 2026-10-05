<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Profiler\ElasticCallRecorder;
use Survos\ElasticBundle\Profiler\ElasticDataCollector;
use Survos\ElasticBundle\Profiler\TraceableElasticsearchClient;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ElasticDataCollectorTest extends TestCase
{
    public function testDuplicatesRespectConnectionIndexAndBodyAndReset(): void
    {
        $recorder = new ElasticCallRecorder();
        $inner = $this->createStub(ElasticsearchClientInterface::class);
        $inner->method('search')->willReturn(['hits' => ['total' => ['value' => 3]]]);
        $first = new TraceableElasticsearchClient($inner, $recorder, endpoint: 'http://localhost:9200');
        $second = new TraceableElasticsearchClient($inner, $recorder, endpoint: 'http://localhost:9201');
        $body = ['query' => ['match' => ['title' => 'music']]];
        $first->search('books', $body);
        $first->search('books', $body);
        $first->search('books', $body);
        $first->search('films', $body);
        $first->search('books', ['size' => 10]);
        $second->search('books', $body);
        // Equal bulk summaries must not count as duplicate searches.
        $first->bulk([]);
        $first->bulk([]);

        $collector = new ElasticDataCollector($recorder);
        $collector->collect(new Request(), new Response());
        self::assertSame(2, $collector->getDuplicates());
        self::assertSame([3, 3, 3, 1, 1, 1, 1, 1], array_column($collector->getCalls(), 'occurrences'));
        $collector->reset();
        $collector->collect(new Request(), new Response());
        self::assertSame(0, $collector->getDuplicates());
        self::assertSame([], $collector->getCalls());
    }

    public function testCurlRoundTripsShellMetacharactersWithoutCredentials(): void
    {
        $body = ['query' => ['match' => ['title' => "O'Reilly \$(echo bad) `echo bad`\n雪"]]];
        $collector = $this->collector($body, 'https://user:secret@example.test:9243/proxy?api_key=private#fragment');
        $command = $collector->getCalls()[0]['curl'];
        self::assertNotNull($command);
        self::assertStringNotContainsString('secret', $command);
        self::assertStringNotContainsString('private', $command);
        // Substitute a harmless shell function for curl: inspect argv without making a request.
        $process = new Process(['/bin/bash', '-c', 'curl() { printf "%s\\0" "$@"; }; ' . $command]);
        $process->mustRun();
        $args = explode("\0", rtrim($process->getOutput(), "\0"));
        self::assertSame('https://example.test:9243/proxy/books/_search', $args[4]);
        self::assertSame($body, json_decode($args[8], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEmptySearchBodyIsAJsonObject(): void
    {
        self::assertStringEndsWith("--data-raw '{}'", $this->collector([], 'http://localhost:9200')->getCalls()[0]['curl']);
    }

    public function testUnknownEndpointDoesNotOfferMisleadingReplay(): void
    {
        self::assertNull($this->collector([], null)->getCalls()[0]['curl']);
    }

    public function testFailedSearchRetainsItsReplayCommand(): void
    {
        $recorder = new ElasticCallRecorder();
        $inner = $this->createStub(ElasticsearchClientInterface::class);
        $inner->method('search')->willThrowException(new \RuntimeException('Invalid query'));
        $client = new TraceableElasticsearchClient($inner, $recorder, endpoint: 'http://localhost:9200');
        try {
            $client->search('books', ['query' => ['invalid' => true]]);
            self::fail('Expected exception');
        } catch (\RuntimeException $error) {
            self::assertSame('Invalid query', $error->getMessage());
        }
        $collector = new ElasticDataCollector($recorder);
        $collector->collect(new Request(), new Response());
        self::assertSame(1, $collector->getErrors());
        self::assertNotNull($collector->getCalls()[0]['curl']);
    }

    public function testPanelEscapesCommandsAndRendersProfilerIcon(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', 'SurvosElastic');
        $reflection = new \ReflectionClass(\Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class);
        $loader->addPath(dirname($reflection->getFileName()) . '/Resources/views', 'WebProfiler');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $template = $twig->load('@SurvosElastic/data_collector/elastic.html.twig');
        $collector = $this->collector(['query' => ['match' => ['title' => '\"><script>alert(1)</script>']]], 'http://localhost:9200');
        $context = ['collector' => $collector];
        $panel = $template->renderBlock('panel', $context);
        self::assertStringContainsString('data-clipboard-text=', $panel);
        self::assertStringContainsString('Copy as cURL', $panel);
        self::assertStringNotContainsString('<script>', $panel);
        self::assertStringContainsString('<svg', $template->renderBlock('menu', $context));
    }

    private function collector(array $body, ?string $endpoint): ElasticDataCollector
    {
        $recorder = new ElasticCallRecorder();
        $inner = $this->createStub(ElasticsearchClientInterface::class);
        $inner->method('search')->willReturn([]);
        (new TraceableElasticsearchClient($inner, $recorder, endpoint: $endpoint))->search('books', $body);
        $collector = new ElasticDataCollector($recorder);
        $collector->collect(new Request(), new Response());

        return $collector;
    }
}
