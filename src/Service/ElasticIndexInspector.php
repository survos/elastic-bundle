<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Service;

use Survos\ElasticBundle\Model\FieldIntent;
use Survos\ElasticBundle\Model\IndexReport;
use Survos\ElasticBundle\Model\SchemaCheck;


/**
 * Compares what a search *declares* against what Elasticsearch actually *holds*.
 *
 * This is the first step toward the two items elastic-bundle's README lists as missing —
 * "settings/analyzer management and schema validation" (survos/mono#42). Validation comes before
 * management: you cannot sensibly manage analyzers until you can see that none are configured.
 *
 * Everything here is read live from Elasticsearch. There is deliberately no IndexInfo entity and
 * no sync machinery — meili-bundle carries that because it was built for 1000+ indexes, and
 * tenants cover that case now.
 */
final readonly class ElasticIndexInspector
{
    public function __construct(
        private AnalysisBuilder $analysis = new AnalysisBuilder(),
    ) {
    }

    /** ES rejects `from + size` beyond this without an explicit index setting. */
    private const int DEFAULT_MAX_RESULT_WINDOW = 10000;

    /** `index.mapping.total_fields.limit` when nothing overrides it. */
    private const int DEFAULT_TOTAL_FIELDS_LIMIT = 1000;

    /**
     * Pure: every Elasticsearch response is passed in.
     *
     * That is deliberate. The caller decides whether to fetch per index (a detail page) or once
     * for a whole pattern (a list page) — inspecting N indexes must not mean 5N round trips. It
     * also makes the checks trivially testable without a client at all.
     *
     * @param array<string, mixed> $mapping    the index's `mappings` block, empty if absent
     * @param array<string, mixed> $settings   flat index settings
     * @param list<string>         $aliases
     * @param array<string, mixed> $stats      `_stats` primaries, or docs/store from _cat
     * @param array<string, mixed> $parameters the search's resolved adapter parameters
     */
    public function inspect(
        string $code,
        ?string $class,
        string $index,
        bool $exists,
        array $mapping,
        array $settings,
        array $aliases,
        array $stats,
        array $parameters,
    ): IndexReport {
        $source = self::documentSource($class, $parameters);

        if (!$exists) {
            return new IndexReport(
                code: $code,
                entityClass: $class,
                index: $index,
                exists: false,
                checks: [SchemaCheck::na('index', 'Index', 'Not created yet — run elastic:index:create.')],
                documentSource: $source,
            );
        }

        $declared = $parameters['mappings'] ?? [];
        $actual = $mapping['properties'] ?? [];

        return new IndexReport(
            code: $code,
            entityClass: $class,
            index: $index,
            exists: true,
            aliases: $aliases,
            declaredFields: $declared,
            actualFields: $actual,
            settings: $settings,
            documentCount: (int) ($stats['docs']['count'] ?? 0),
            storeSizeBytes: (int) ($stats['store']['size_in_bytes'] ?? 0),
            checks: [
                $this->checkAlias($aliases),
                $this->checkAnalysis($settings),
                $this->checkDynamic($mapping),
                $this->checkDrift($declared, $actual),
                $this->checkFieldLimit($actual, $settings),
                $this->checkResultWindow((int) ($stats['docs']['count'] ?? 0), $settings),
            ],
            documentSource: $source,
        );
    }

    /**
     * Traces every #[Field]-declared intent through the adapter parameters and into the live
     * mapping — the "why isn't my facet showing up" table.
     *
     * @param list<\Survos\FieldBundle\Model\FieldDescriptor> $descriptors
     * @param array<string, mixed>                           $parameters
     * @param array<string, mixed>                           $mapping     the live `mappings` block
     *
     * @return list<FieldIntent>
     */
    public function fieldIntents(array $descriptors, array $parameters, array $mapping, bool $indexExists): array
    {
        $facetFields = $parameters['facetFields'] ?? [];
        $searchFields = $parameters['searchFields'] ?? [];
        $sortFields = $parameters['sortFields'] ?? [];
        $properties = $mapping['properties'] ?? [];

        $intents = [];
        foreach ($descriptors as $descriptor) {
            $name = $descriptor->name;

            // facetFields maps property => the ES field to aggregate on (often `name.keyword`);
            // the others are plain lists.
            $facetField = $facetFields[$name] ?? null;
            $inFacet = \array_key_exists($name, $facetFields);

            $intent = new FieldIntent(
                name: $name,
                wantsFacet: $descriptor->facet || $descriptor->filterable,
                wantsSearchable: $descriptor->searchable,
                wantsSortable: $descriptor->sortable,
                inFacetFields: $inFacet,
                inSearchFields: \in_array($name, $searchFields, true),
                inSortFields: \in_array($name, $sortFields, true) || \array_key_exists($name, $sortFields),
                facetField: \is_string($facetField) ? $facetField : null,
                actualType: self::mappedType($properties, $name),
                indexExists: $indexExists,
            );

            // Only worth a row if the entity asked for something or Elasticsearch knows the field.
            if ($intent->wanted || null !== $intent->actualType) {
                $intents[] = $intent;
            }
        }

        return $intents;
    }

    /**
     * The mapped type of `$name`, following a `.keyword` subfield when that is what a facet would
     * actually aggregate on.
     *
     * @param array<string, mixed> $properties
     */
    private static function mappedType(array $properties, string $name): ?string
    {
        $definition = $properties[$name] ?? null;
        if (!\is_array($definition)) {
            return null;
        }

        $type = \is_string($definition['type'] ?? null) ? $definition['type'] : null;

        // A text field with a keyword subfield is aggregatable through that subfield, which is
        // exactly what the derived facetFields point at — so report the usable type.
        if ('text' === $type && \is_array($definition['fields']['keyword'] ?? null)) {
            return 'text + .keyword';
        }

        return $type;
    }

    /**
     * Mirrors ElasticIndexService::documents(): a configured documentProvider wins, otherwise the
     * entity is iterated through Doctrine.
     *
     * Deliberately does not *invoke* a callable provider — resolving it can be expensive or have
     * side effects, and for this purpose "a provider is configured" is the whole answer.
     *
     * @param array<string, mixed> $parameters
     */
    private static function documentSource(?string $class, array $parameters): string
    {
        if (null !== ($parameters['documentProvider'] ?? null)) {
            return 'provider';
        }

        return null === $class ? 'none' : 'doctrine';
    }

    /** @param list<string> $aliases */
    private function checkAlias(array $aliases): SchemaCheck
    {
        if ($aliases !== []) {
            return SchemaCheck::ok('alias', 'Alias', sprintf('Served through %s — a reindex can swap atomically.', implode(', ', $aliases)));
        }

        return SchemaCheck::warn(
            'alias',
            'Alias',
            'Addressed directly, with no alias. A reindex means deleting this index first, so the site serves zero results until populate finishes.',
            issue: 1,
        );
    }

    /** @param array<string, mixed> $settings */
    private function checkAnalysis(array $settings): SchemaCheck
    {
        $analyzers = [];
        foreach ($settings as $key => $value) {
            // Flat settings: index.analysis.analyzer.<name>.<option>
            if (\is_string($key) && preg_match('#^index\.analysis\.analyzer\.([^.]+)\.#', $key, $m) === 1) {
                $analyzers[$m[1]] = true;
            }
        }

        // The language baked into this index at creation, which is the only thing that can differ
        // from configuration -- analysis is static, so a config change does nothing until rebuild.
        $indexed = $settings['index.analysis.filter.'.AnalysisBuilder::STEMMER_FILTER.'.language'] ?? null;
        $wanted = $this->analysis->language();

        if (null !== $wanted && $indexed !== $wanted) {
            return SchemaCheck::warn(
                'analysis',
                'Analyzers',
                sprintf(
                    'Configured for "%s" but this index was built with %s. index.analysis is a static setting, so the configuration has had no effect on it — run elastic:index:rebuild.',
                    $wanted,
                    null === $indexed ? 'no custom analyzer' : sprintf('"%s"', $indexed),
                ),
                issue: 2,
            );
        }

        if ($analyzers !== []) {
            return SchemaCheck::ok('analysis', 'Analyzers', sprintf(
                'Custom analyzer configured: %s%s.',
                implode(', ', array_keys($analyzers)),
                null !== $indexed ? sprintf(' (%s stemming)', $indexed) : '',
            ));
        }

        return SchemaCheck::warn(
            'analysis',
            'Analyzers',
            'No custom analyzer — every text field uses the default "standard" analyzer. That means no language stemming, no ASCII folding for accented names, no edge-ngram autocomplete and no synonyms. Searches work; they are just worse than they should be, and comparing this against Meilisearch is not a fair test.',
            issue: 2,
        );
    }

    /** @param array<string, mixed> $mapping */
    private function checkDynamic(array $mapping): SchemaCheck
    {
        $dynamic = $mapping['dynamic'] ?? true;
        // ES accepts the strings and the booleans interchangeably.
        $normalized = \is_bool($dynamic) ? ($dynamic ? 'true' : 'false') : (string) $dynamic;

        if (\in_array($normalized, ['strict', 'false'], true)) {
            return SchemaCheck::ok('dynamic', 'Dynamic mapping', sprintf('dynamic: %s — undeclared fields cannot silently enter the mapping.', $normalized));
        }

        return SchemaCheck::warn(
            'dynamic',
            'Dynamic mapping',
            sprintf('dynamic: %s. Any document field missing from the declared mapping is inferred by Elasticsearch and written into the schema permanently — and a mapped type can never be changed afterwards, only reindexed into a new index.', $normalized),
            issue: 2,
        );
    }

    /**
     * The schema validation itself: declared vs actual.
     *
     * @param array<string, mixed> $declared
     * @param array<string, mixed> $actual
     */
    private function checkDrift(array $declared, array $actual): SchemaCheck
    {
        $missing = array_diff(array_keys($declared), array_keys($actual));
        $unexpected = array_diff(array_keys($actual), array_keys($declared));
        $retyped = [];

        foreach ($declared as $field => $definition) {
            $declaredType = \is_array($definition) ? ($definition['type'] ?? null) : null;
            $actualType = \is_array($actual[$field] ?? null) ? ($actual[$field]['type'] ?? null) : null;
            if (null !== $declaredType && null !== $actualType && $declaredType !== $actualType) {
                $retyped[] = sprintf('%s (declared %s, actual %s)', $field, $declaredType, $actualType);
            }
        }

        if ($missing === [] && $unexpected === [] && $retyped === []) {
            return SchemaCheck::ok('drift', 'Schema drift', sprintf('%d declared fields, all present and correctly typed.', \count($declared)));
        }

        $parts = [];
        if ($retyped !== []) {
            // Worst case: the running index cannot be corrected in place at all.
            $parts[] = sprintf('type mismatch on %s — this cannot be fixed in place, it needs a reindex', implode(', ', $retyped));
        }
        if ($missing !== []) {
            $parts[] = sprintf('declared but absent from Elasticsearch: %s (the index predates a mapping change)', implode(', ', $missing));
        }
        if ($unexpected !== []) {
            $parts[] = sprintf('present in Elasticsearch but never declared: %s (added by dynamic mapping from indexed documents)', implode(', ', $unexpected));
        }

        return SchemaCheck::warn('drift', 'Schema drift', ucfirst(implode('; ', $parts)).'.', issue: 9);
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $settings
     */
    private function checkFieldLimit(array $actual, array $settings): SchemaCheck
    {
        $limit = (int) ($settings['index.mapping.total_fields.limit'] ?? self::DEFAULT_TOTAL_FIELDS_LIMIT);
        $count = \count($actual);
        $detail = sprintf('%d of %d mapped fields used.', $count, $limit);

        // 80% is arbitrary but it is the point where a dynamic-mapping leak stops being theoretical.
        return $count >= (int) ($limit * 0.8)
            ? SchemaCheck::warn('fields', 'Field count', $detail.' Approaching the limit — check the drift row for fields dynamic mapping invented.', issue: 2)
            : SchemaCheck::ok('fields', 'Field count', $detail);
    }

    /** @param array<string, mixed> $settings */
    private function checkResultWindow(int $documents, array $settings): SchemaCheck
    {
        $window = (int) ($settings['index.max_result_window'] ?? self::DEFAULT_MAX_RESULT_WINDOW);

        if ($documents <= $window) {
            return SchemaCheck::ok('paging', 'Deep paging', sprintf('%s documents, within the %s result window.', number_format($documents), number_format($window)));
        }

        return SchemaCheck::warn(
            'paging',
            'Deep paging',
            sprintf('%s documents against a %s result window. Paging past that throws, and there is no search_after/PIT fallback yet — so the tail of this index is unreachable and it cannot be streamed out.', number_format($documents), number_format($window)),
            issue: 7,
        );
    }
}
