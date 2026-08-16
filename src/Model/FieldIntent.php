<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Model;

/**
 * One field, traced through all three layers it has to survive to become a working facet.
 *
 * A `#[Field(facet: true)]` that does not appear on the page can have failed at any of three hops,
 * and each looks identical from the browser:
 *
 *   1. the attribute        — #[Field(facet: true)] on the entity
 *   2. the adapter          — did it reach the search's resolved facetFields/searchFields/sortFields?
 *   3. Elasticsearch        — is it mapped, and as an aggregatable type?
 *
 * Comparing 1 against 2 catches derivation bugs (a blob column skipped by auto-discovery, a name
 * that never made it into FILTERABLE_FIELDS). Comparing 2 against 3 catches migration debt — the
 * mapping is immutable, so a field added after the index was created is declared but absent until
 * a rebuild.
 */
final class FieldIntent
{
    public function __construct(
        public readonly string $name,
        /** Declared on the entity via #[Field]. */
        public readonly bool $wantsFacet = false,
        public readonly bool $wantsSearchable = false,
        public readonly bool $wantsSortable = false,
        /** Reached the search's resolved adapter parameters. */
        public readonly bool $inFacetFields = false,
        public readonly bool $inSearchFields = false,
        public readonly bool $inSortFields = false,
        /** The Elasticsearch field a facet would aggregate on, e.g. `tags.keyword`. */
        public readonly ?string $facetField = null,
        /** Mapped type in the live index, null when Elasticsearch has never seen it. */
        public readonly ?string $actualType = null,
        public readonly bool $indexExists = false,
    ) {
    }

    /**
     * Aggregations need a doc-values type. `text` is the one that silently produces nothing —
     * Elasticsearch disables fielddata by default, which is why the mapping gives text columns a
     * `.keyword` subfield and facets point at that.
     */
    public bool $aggregatable {
        get => null !== $this->actualType && 'text' !== $this->actualType;
    }

    /** null when nothing is wrong; otherwise the first hop that broke, in pipeline order. */
    public ?string $problem {
        get {
            if ($this->wantsFacet && !$this->inFacetFields) {
                return 'Declared as a facet, but it never reached the search\'s facetFields — the derivation dropped it, so nothing downstream can work.';
            }

            if ($this->wantsSearchable && !$this->inSearchFields) {
                return 'Declared searchable, but absent from the search\'s searchFields.';
            }

            if ($this->wantsSortable && !$this->inSortFields) {
                return 'Declared sortable, but absent from the search\'s sortFields.';
            }

            if (!$this->indexExists) {
                return null; // Nothing to say about Elasticsearch until the index is created.
            }

            if (($this->inFacetFields || $this->inSearchFields || $this->inSortFields) && null === $this->actualType) {
                return 'The search uses this field but Elasticsearch has no mapping for it — added after the index was created. Mappings are immutable, so this needs a rebuild.';
            }

            if ($this->inFacetFields && !$this->aggregatable) {
                return \sprintf('Faceted on a "%s" field, which Elasticsearch cannot aggregate — it needs a keyword type or a .keyword subfield.', (string) $this->actualType);
            }

            return null;
        }
    }

    public bool $wanted {
        get => $this->wantsFacet || $this->wantsSearchable || $this->wantsSortable;
    }
}
