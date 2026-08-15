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

    public function testBulkIndexBatchesAndRefreshes(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::exactly(2))->method('bulk')->willReturn(['errors' => false]);
        $client->expects(self::once())->method('refresh')->with('packages');

        $count = $this->service()->bulkIndex($client, 'packages', [
            ['id' => 'one', 'document' => ['name' => 'One']],
            ['id' => 'two', 'document' => ['name' => 'Two']],
            ['id' => 'three', 'document' => ['name' => 'Three']],
        ], 2);

        self::assertSame(3, $count);
    }

    public function testEmptyDocumentSetDoesNotRefresh(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::never())->method('bulk');
        $client->expects(self::never())->method('refresh');

        self::assertSame(0, $this->service()->bulkIndex($client, 'packages', [], 100));
    }

    /**
     * A partial bulk failure must not be reported as success. Elasticsearch returns HTTP 200
     * with errors:true per item, so the response has to be inspected -- this is exactly how the
     * nested-object mapping failure surfaced when json columns were mapped as keyword.
     */
    public function testBulkSurfacesPerDocumentErrors(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->method('bulk')->willReturn([
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
