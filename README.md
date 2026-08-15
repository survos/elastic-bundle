# Survos Elastic Bundle

Elasticsearch **index lifecycle** for Symfony. The query and faceted-UI half lives in
[survos/search-bundle](https://github.com/survos/search-bundle); this bundle owns everything
that writes.

## Why it's separate

`survos/search-bundle` adapters are read-only, and it must not require an engine client — an
app using only the SQLite FTS5 or Postgres BM25 adapter shouldn't pay for
`elasticsearch/elasticsearch`, `elastic/transport` and OpenTelemetry. Index ownership
therefore lives in the engine's own bundle, exactly as `survos/meili-bundle` already does for
Meilisearch.

This is deliberately **not** FOSElasticaBundle: that brings its own query layer (`Finder`,
its own Query objects), which would compete with search-bundle's `Query`/`ResultSet`/facets,
and its mappings live in ES-specific YAML instead of deriving from `#[Field]` metadata.

## Commands

```bash
bin/console elastic:index:create   [code] [--drop]
bin/console elastic:index:populate [code] [--batch-size=250] [--limit=N]
bin/console elastic:index:status   [code]
```

Mappings are **not** computed here — they come from the search's resolved adapter parameters
in search-bundle, so querying and indexing can't drift apart.

## Doctrine sync

`ElasticSpoolDoctrineListener` records changed entity ids in `postPersist`/`postUpdate` and
appends them to a spool file in `postFlush`. It never calls Elasticsearch: a database write
must not depend on the search engine being up, and an import that flushes the same entity
four times must not index it four times. The spool means "this document needs
reconciliation"; the reindex step loads current state and decides what actually changed.

```yaml
# config/packages/survos_elastic.yaml
survos_elastic:
    spool_dir: '%kernel.project_dir%/var/elastic-spool'
    spool_enabled: true
```

## Not built yet

- Messenger handlers for async reconciliation (meili-bundle has `BatchIndexEntitiesMessage`
  and friends); today the spool is written but nothing consumes it.
- Settings/analyzer management and schema validation.
- The embedding cache. Vectors stay off until it exists — see search-bundle's
  `docs/elasticsearch.md`.
