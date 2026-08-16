<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Service\AnalysisBuilder;

final class AnalysisBuilderTest extends TestCase
{
    public function testNoLanguageMeansNoSettingsAndNoMappingChange(): void
    {
        $builder = new AnalysisBuilder();
        $properties = ['title' => ['type' => 'text']];

        self::assertFalse($builder->isConfigured());
        self::assertSame([], $builder->settings());
        self::assertSame($properties, $builder->applyTo($properties), 'unconfigured must be a no-op, not a partial mapping');
    }

    public function testFilterOrderIsLowercaseThenFoldThenStem(): void
    {
        $analysis = (new AnalysisBuilder('english'))->settings()['analysis'];

        // Order matters: folding wants consistent case, stemming wants normalised tokens.
        self::assertSame(
            ['lowercase', 'asciifolding', AnalysisBuilder::STEMMER_FILTER],
            $analysis['analyzer'][AnalysisBuilder::ANALYZER]['filter'],
        );
        self::assertSame('english', $analysis['filter'][AnalysisBuilder::STEMMER_FILTER]['language']);
        self::assertSame('standard', $analysis['analyzer'][AnalysisBuilder::ANALYZER]['tokenizer']);
    }

    public function testAsciiFoldingCanBeTurnedOff(): void
    {
        $analysis = (new AnalysisBuilder('english', asciiFolding: false))->settings()['analysis'];

        self::assertSame(['lowercase', AnalysisBuilder::STEMMER_FILTER], $analysis['analyzer'][AnalysisBuilder::ANALYZER]['filter']);
    }

    /**
     * Only `text` gets the analyzer. Stemming a keyword would turn a "Running Shoes" facet bucket
     * into "run shoe", and keyword is exactly what facets and sorts aggregate on.
     */
    public function testOnlyTextFieldsGetTheAnalyzer(): void
    {
        $properties = (new AnalysisBuilder('english'))->applyTo([
            'title' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'tags' => ['type' => 'keyword'],
            'year' => ['type' => 'long'],
            'released' => ['type' => 'date'],
        ]);

        self::assertSame(AnalysisBuilder::ANALYZER, $properties['title']['analyzer']);
        self::assertArrayNotHasKey('analyzer', $properties['tags']);
        self::assertArrayNotHasKey('analyzer', $properties['year']);
        self::assertArrayNotHasKey('analyzer', $properties['released']);
        // The subfield must stay unanalysed.
        self::assertArrayNotHasKey('analyzer', $properties['title']['fields']['keyword']);
    }
}
