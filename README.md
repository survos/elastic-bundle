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
bin/console elastic:index:status   [code]
bin/console elastic:index:create   [code] [--drop] [--strict|--no-strict]
bin/console elastic:index:populate [code] [--batch-size=250] [--limit=N]
bin/console elastic:index:rebuild  [code] [--keep-old] [--batch-size=250]
bin/console elastic:index:delete   [code] [--force]
```

Mappings are **not** computed here — they come from the search's resolved adapter parameters
in search-bundle, so querying and indexing can't drift apart.

## Demo: a faceted, stemmed search in five minutes

Reproduced end to end against `survos-sites/bench` (Elasticsearch 9.5.0, 300 films). Every
number below is from that run.

```yaml
# config/packages/survos_search.yaml
survos_search:
    default_adapter: es
    adapters:
        es: { dsn: '%env(ELASTICSEARCH_DSN)%' }

# config/packages/survos_elastic.yaml
survos_elastic:
    analysis:
        language: english
        ascii_folding: true
```

```bash
bin/console elastic:index:create app_movie     # bench_movie -> bench_movie_20260816113526
bin/console elastic:index:populate app_movie   # indexed 300 documents
```

Then open `/entity/app_movie/search`. The page is served by search-bundle's ux-search
components against the Elasticsearch adapter — 300 results, a Year range slider whose 1986–2023
bounds come from a live `stats` aggregation, and term facets (`mcu`, `superhero`, `marvel`, …)
from `terms` aggregations.

**Stemming is the part worth demonstrating**, because it is invisible until you look for it:

| query | results |
|---|---|
| `?query=village` | 2 |
| `?query=villages` | 2 |
| `?query=war` | 13 |

Singular and plural returning the same hits is the `english` stemmer. Without
`survos_elastic.analysis` those numbers are 0 and 2 — the default `standard` analyzer does no
stemming at all. On the same index you can see both behaviours side by side:

```bash
curl "$ES/bench_movie/_analyze" -H 'Content-Type: application/json' \
  -d '{"analyzer":"standard","text":"Running through Kovács'"'"' villages"}'
#   -> running, through, kovács, villages

curl "$ES/bench_movie/_analyze" -H 'Content-Type: application/json' \
  -d '{"analyzer":"survos_text","text":"Running through Kovács'"'"' villages"}'
#   -> run, through, kovac, villag
```

### Changing the analyzer

`index.analysis` is a **static** setting — editing the config does nothing to an index that
already exists. The admin page says so rather than letting you wonder:

> Configured for "hungarian" but this index was built with "english". `index.analysis` is a
> static setting, so the configuration has had no effect on it — run `elastic:index:rebuild`.

```bash
bin/console elastic:index:rebuild app_movie
```

builds a new generation, populates it, swaps the alias atomically, and drops the old one. The
old index serves every query until the moment of the swap, and a failure mid-populate leaves the
live alias untouched.

### Admin pages

`/admin/elastic/` lists every registered search with its index, document source and schema
status; `/admin/elastic/{code}` is the diagnostic page — alias, analyzers, dynamic mapping,
declared-vs-actual drift, field count, deep-paging headroom, and a **Field intent** table tracing
each `#[Field]` through the adapter into the live mapping. That last one answers "why isn't my
facet showing up" without guessing which of the three layers dropped it.

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

- The embedding cache. Vectors stay off until it exists — see search-bundle's
  `docs/elasticsearch.md`.
- Streaming/resumable populate, conditional indexing, relation traversal in auto-mapping, a raw
  request-body hook, `search_after` deep paging and suggesters — see
  [survos/mono#42](https://github.com/survos/mono/issues/42) items 3–8.

Settings/analyzer management and schema validation are **done** — see the demo above and the
admin pages.
