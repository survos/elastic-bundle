<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;

final class ElasticIndexServiceTest extends TestCase
{
    private function service(): ElasticIndexService
    {
        // bulkIndex() takes its client as an argument and touches no collaborator, so the
        // constructor dependencies are irrelevant here.
        return (new \ReflectionClass(ElasticIndexService::class))->newInstanceWithoutConstructor();
    }

    public function testBulkIndexBatchesWithoutRefreshingByDefault(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::exactly(2))->method('bulk')->willReturn(['errors' => false]);
        $client->expects(self::never())->method('refresh');

        $count = $this->service()->bulkIndex($client, 'packages', [
            ['id' => 'one', 'document' => ['name' => 'One']],
            ['id' => 'two', 'document' => ['name' => 'Two']],
            ['id' => 'three', 'document' => ['name' => 'Three']],
        ], 2);

        self::assertSame(3, $count);
    }

    public function testBulkIndexRefreshesOnceWhenAsked(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::exactly(2))->method('bulk')->willReturn(['errors' => false]);
        $client->expects(self::once())->method('refresh')->with('packages');

        $this->service()->bulkIndex($client, 'packages', [
            ['id' => 'one', 'document' => ['name' => 'One']],
            ['id' => 'two', 'document' => ['name' => 'Two']],
        ], 1, refresh: true);
    }

    public function testEmptyDocumentSetDoesNotRefresh(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::never())->method('bulk');
        $client->expects(self::never())->method('refresh');

        self::assertSame(0, $this->service()->bulkIndex($client, 'packages', [], 100, refresh: true));
    }

    /** Large OCR/AI documents split a request long before the document count does. */
    public function testBulkIndexSplitsByBytes(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::exactly(3))->method('bulk')->willReturn(['errors' => false]);

        $text = str_repeat('x', 1000);
        $this->service()->bulkIndex($client, 'assets', [
            ['id' => 'a', 'document' => ['ocr' => $text]],
            ['id' => 'b', 'document' => ['ocr' => $text]],
            ['id' => 'c', 'document' => ['ocr' => $text]],
        ], 100, maxBytes: 500);
    }

    public function testUpsertSendsUpdateWithDocAsUpsert(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::once())->method('bulk')
            ->with([
                ['update' => ['_index' => 'assets', '_id' => 'a']],
                ['doc' => ['title' => 'A', 'marking' => null], 'doc_as_upsert' => true],
            ])
            ->willReturn(['errors' => false]);

        $this->service()->bulkIndex($client, 'assets', [
            ['id' => 'a', 'document' => ['title' => 'A', 'marking' => null]],
        ], 100, upsert: true);
    }

    public function testUpdateErrorsAreSurfacedToo(): void
    {
        $client = $this->createStub(ElasticsearchClientInterface::class);
        $client->method('bulk')->willReturn([
            'errors' => true,
            'items' => [['update' => ['_id' => 'a', 'error' => ['type' => 'strict_dynamic_mapping_exception']]]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"a".*strict_dynamic_mapping_exception/');

        $this->service()->bulkIndex($client, 'assets', [['id' => 'a', 'document' => ['surprise' => 1]]], 100, upsert: true);
    }

    public function testMappedNullsFillOmittedFields(): void
    {
        $method = new \ReflectionMethod(ElasticIndexService::class, 'withMappedNulls');
        $out = iterator_to_array($method->invoke($this->service(), [['id' => 'a', 'document' => ['title' => 'A']]], ['title', 'aiOcrText']), false);

        self::assertSame(['title' => 'A', 'aiOcrText' => null], $out[0]['document']);
    }

    /**
     * A partial bulk failure must not be reported as success. Elasticsearch returns HTTP 200
     * with errors:true per item, so the response has to be inspected -- this is exactly how the
     * nested-object mapping failure surfaced when json columns were mapped as keyword.
     */
    public function testBulkSurfacesPerDocumentErrors(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::once())->method('bulk')->willReturn([
            'errors' => true,
            'items' => [[
                'index' => [
                    '_id' => 'contao--manager-bundle',
                    'error' => ['type' => 'illegal_argument_exception', 'reason' => 'Expected text but found START_OBJECT'],
                ],
            ]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contao--manager-bundle.*START_OBJECT/');

        $this->service()->bulkIndex($client, 'packages', [
            ['id' => 'contao--manager-bundle', 'document' => ['data' => ['nested' => true]]],
        ], 100);
    }
}
