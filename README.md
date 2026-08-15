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

`ElasticSpoolDoctrineListener` collects changed ids in `postPersist`/`postUpdate`/`postRemove`
and hands them off in `postFlush`. It never calls Elasticsearch inline: a database write must
not depend on the search engine being up, and an HTTP request must not wait on it.

Messages carry **ids, never documents**. The worker loads current state when it runs, so
duplicate messages are cheap and correct -- an import that flushes the same entity four times
produces one reindex from the final state, not four racing writes.

```yaml
# config/packages/survos_elastic.yaml
survos_elastic:
    spool_dir: '%kernel.project_dir%/var/elastic-spool'
    spool_enabled: true
    async: true        # dispatch through Messenger
    batch_size: 500    # ids per message
```

### Two modes

**`async: true`** (default, needs symfony/messenger) dispatches `ReindexDocuments` /
`RemoveDocuments`, chunked by `batch_size`, so one enormous flush becomes several bounded jobs.

**`async: false`**, or no bus installed, writes a JSONL spool drained by `elastic:spool:flush`.
This is the right mode for a bulk import: a line per id costs nothing, and reconciling 400k
ids once at the end beats dispatching 400k messages.

```bash
bin/console elastic:spool:flush [FQCN] [--batch-size=500] [--async]
```

### Routing

Unrouted messages are handled **synchronously** -- which still works, but the flush then waits
on Elasticsearch, defeating the point. Route them to get real async:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'Survos\ElasticBundle\Message\ReindexDocuments': async
            'Survos\ElasticBundle\Message\RemoveDocuments': async
```

## Not built yet

- Settings/analyzer management and schema validation.
- The embedding cache. Vectors stay off until it exists — see search-bundle's
  `docs/elasticsearch.md`.
