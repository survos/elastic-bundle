<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Model\SchemaCheck;
use Survos\ElasticBundle\Service\ElasticIndexInspector;

final class ElasticIndexInspectorTest extends TestCase
{
    public function testAMissingIndexReportsNothingElse(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: false,
            mapping: [],
            settings: [],
            aliases: [],
            stats: [],
            parameters: [],
        );

        self::assertFalse($report->exists);
        self::assertSame(0, $report->warningCount);
        self::assertCount(1, $report->checks);
    }

    /** The defaults every index currently gets — each one is a checklist item in survos/mono#42. */
    public function testADefaultIndexWarnsAboutAliasAnalyzersAndDynamicMapping(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: true,
            mapping: ['properties' => ['name' => ['type' => 'text']]],
            settings: ['index.number_of_shards' => '1'],
            aliases: [],
            stats: [],
            parameters: ['mappings' => ['name' => ['type' => 'text']]],
        );

        $byId = self::byId($report->checks);

        self::assertSame('warn', $byId['alias']->status, 'no alias means a reindex serves nothing while it runs');
        self::assertSame(1, $byId['alias']->issue);
        self::assertSame('warn', $byId['analysis']->status, 'the default standard analyzer does no stemming');
        self::assertSame('warn', $byId['dynamic']->status, 'dynamic defaults to true when unset');
        self::assertStringContainsString('dynamic: true', $byId['dynamic']->detail);
        // Declared matches actual, so drift is clean even though everything else warns.
        self::assertSame('ok', $byId['drift']->status);
    }

    public function testConfiguredAnalyzersAndAliasesAndStrictDynamicAreAccepted(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: true,
            mapping: ['dynamic' => 'strict', 'properties' => ['name' => ['type' => 'text']]],
            settings: ['index.analysis.analyzer.english_folding.tokenizer' => 'standard'],
            aliases: ['pkg'],
            stats: [],
            parameters: ['mappings' => ['name' => ['type' => 'text']]],
        );

        $byId = self::byId($report->checks);

        self::assertSame('ok', $byId['alias']->status);
        self::assertSame('ok', $byId['analysis']->status);
        self::assertStringContainsString('english_folding', $byId['analysis']->detail);
        self::assertSame('ok', $byId['dynamic']->status);
    }

    /** The schema validation itself: three distinct kinds of disagreement. */
    public function testDriftDistinguishesMissingUndeclaredAndRetypedFields(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: true,
            mapping: ['properties' => [
                'name' => ['type' => 'text'],
                'downloads' => ['type' => 'text'],   // declared as long below
                'inventedByEs' => ['type' => 'float'],
            ]],
            settings: [],
            aliases: [],
            stats: [],
            parameters: ['mappings' => [
                'name' => ['type' => 'text'],
                'downloads' => ['type' => 'long'],
                'neverIndexed' => ['type' => 'keyword'],
            ]],
        );

        $drift = self::byId($report->checks)['drift'];
        self::assertSame('warn', $drift->status);
        self::assertSame(9, $drift->issue);
        self::assertStringContainsString('downloads (declared long, actual text)', $drift->detail);
        self::assertStringContainsString('neverIndexed', $drift->detail);
        self::assertStringContainsString('inventedByEs', $drift->detail);

        $statuses = array_column($report->fieldComparison, 'status', 'field');
        self::assertSame([
            'downloads' => 'mismatch',
            'inventedByEs' => 'undeclared',
            'name' => 'ok',
            'neverIndexed' => 'missing',
        ], $statuses);
    }

    /**
     * Real case caught on the live `package` index: 14,244 documents against the default 10,000
     * result window, so its tail is simply unreachable.
     */
    public function testDocumentsBeyondTheResultWindowAreFlagged(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: true,
            mapping: [],
            settings: [],
            aliases: [],
            stats: ['docs' => ['count' => 14244], 'store' => ['size_in_bytes' => 5913037]],
            parameters: [],
        );

        $paging = self::byId($report->checks)['paging'];
        self::assertSame('warn', $paging->status);
        self::assertSame(7, $paging->issue);
        self::assertStringContainsString('14,244', $paging->detail);
        self::assertSame(14244, $report->documentCount);
    }

    public function testAnExplicitResultWindowIsHonoured(): void
    {
        $report = (new ElasticIndexInspector())->inspect(
            'pkg',
            null,
            'pkg',
            exists: true,
            mapping: [],
            settings: ['index.max_result_window' => '100000'],
            aliases: [],
            stats: ['docs' => ['count' => 14244]],
            parameters: [],
        );

        self::assertSame('ok', self::byId($report->checks)['paging']->status);
    }

    /**
     * @param list<SchemaCheck> $checks
     *
     * @return array<string, SchemaCheck>
     */
    private static function byId(array $checks): array
    {
        return array_column(array_map(static fn (SchemaCheck $c): array => ['id' => $c->id, 'check' => $c], $checks), 'check', 'id');
    }

}
