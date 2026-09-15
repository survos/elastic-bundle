# Migrating an app from Meilisearch to Elasticsearch

Written 2026-09-15 from two real migrations: `survos-sites/bench` (fully local) and
`survos-sites/packages` (local and on fsn1). Every gotcha below was hit in one of them.

## The decision

Elasticsearch is the default engine. Meilisearch stays only where it already works, and **an app
that has Elasticsearch drops Meilisearch entirely** rather than running both.

Why:

- API Platform has first-class Elasticsearch support. Our Meilisearch integration for API
  Platform was not accepted upstream, so the `tacman/core-1` `dev-meilisearch` fork is a dead end.
- Meili key management was the recurring pain: a master key in every app that indexes, search
  keys committed to `.env` files, and a per-app `index_info` table storing keys in plain text.
  Elasticsearch keys are provisioned once per app on the server, scoped to the app's index
  prefix, and never stored in the app's database.
- Browser search goes through the app (search-bundle's `/instant-search`), so no engine key
  ever reaches the browser. Elasticsearch has no public endpoint at all.

`search-bundle/docs/engine-selection.md` describes the older "keep every engine" policy and the
evaluation that led here.

## Before you start

Tag the last Meilisearch state so it can be compared later:

```bash
git tag -a search-baseline-2026-09-15 -m "Meilisearch baseline before dropping Meili for Elasticsearch"
git push origin search-baseline-2026-09-15
```

bench, kpa and packages carry this tag.

## What replaces what

| Meilisearch (meili-bundle) | Elasticsearch |
|---|---|
| `#[MeiliIndex(filterable:, sortable:, searchable:)]` | Nothing on the entity beyond `#[EntityMeta]` plus the `FILTERABLE_FIELDS` / `SORTABLE_FIELDS` / `SEARCHABLE_FIELDS` constants. search-bundle's `AutoEntitySearchPass` discovers entities from field-bundle's `EntityMetaRegistry`, not from `#[MeiliIndex]`. |
| `MEILI_PREFIX` | `survos_search.index_prefix`. It defaults to `%env(default::MEILI_PREFIX)%`, so **set it explicitly before deleting `MEILI_PREFIX`** or the index name changes (`packages_package` would become `package`). |
| meili-bundle Doctrine listener + `BatchIndexEntitiesMessage` on a `meili` transport | `ElasticSpoolDoctrineListener` + `ReindexDocuments`/`RemoveDocuments` on a dedicated `elastic` transport and worker (see the README). |
| `meili:index`, `meili:populate`, `meili:settings:update` | `elastic:index:create`, `populate`, `rebuild` (alias swap), `status`. |
| `index_info` registry (settings + keys per index) | Nothing. elastic-bundle reads index state live from the cluster; `/admin/elastic/` shows it. |
| `/meili/proxy/{path}` or browser → Meili with a search key | `POST /instant-search` (search-bundle), limited to `survos_search.public_searches`. |
| `insta` controller + `templates/js/<index>.html.twig` | search-bundle's `instantsearch` Stimulus controller + a Twig **source** template served over HTTP and rendered per hit in the browser. |
| The json modal (`{}` button, refetched from Meili) | The same `{}` button, built into the `instantsearch` controller, showing the hit already in the response via `@andypf/json-viewer`. Turn off with `rawJson: false`. |
| `ApiPlatform\Meilisearch\State\Options` collection operations | Remove them. API Platform's own Elasticsearch provider is the replacement when a JSON collection over the index is needed. |
| meili-bundle AI tools/chat workspaces | Nothing yet. Chat will be built on Elasticsearch (packages is the testbed). |

## Migrating the app

1. **Search config.** Point search at Elasticsearch and name the public searches:

   ```yaml
   # config/packages/survos_search.yaml
   survos_search:
       default_adapter: es
       public_searches: [app_package]
       index_prefix: '%env(SEARCH_INDEX_PREFIX)%'
       adapters:
           es: '%env(ELASTICSEARCH_DSN)%'
   ```

   ```dotenv
   ELASTICSEARCH_DSN=elasticsearch://127.0.0.1:9200
   SEARCH_INDEX_PREFIX=packages_
   ```

2. **Build the index** before touching the UI, so there is something to look at:

   ```bash
   bin/console elastic:index:rebuild app_package
   bin/console elastic:index:status
   ```

3. **Browser search page.** Render the `instantsearch` controller with an endpoint, a search name,
   a template URL and optional sorts, plus targets for query, hits, stats, pagination, current,
   clear, sort, facet and range. The complete working examples are bench's
   `templates/search/browse.html.twig` + `card.browser.twig` and packages'
   `templates/search/index.html.twig` + `package.browser.twig`. Enable the controller in
   `assets/controllers.json` (`"instantsearch": {"enabled": true, "fetch": "eager"}`).

   Don't pass `context: {}`. Twig encodes an empty hash as `[]`, the controller declares
   `context` as an Object, and InstantSearch dies with *expected value of type "object" but instead
   got value "[]"*. Omit the key when there's nothing to pass.

   The card template is Twig source rendered in the browser. Escape values with `|e`; only print
   `_highlightResult.*.value|raw`, which Elasticsearch HTML-encodes.

4. **Remove Meilisearch.**

   ```bash
   composer remove survos/meili-bundle meilisearch/meilisearch-php
   bin/console importmap:remove meilisearch @meilisearch/instant-meilisearch
   ```

   Flex unconfigures the bundle and its `controllers.json` entries. By hand:

   - Delete `config/packages/survos_meili.yaml`, the `meili` Messenger transport and its
     `BatchIndexEntitiesMessage` routing, and the `###> survos/meili-bundle ###` block in `.env`
     (keep anything else that lived in it, like `OPENAI_API_KEY`).
   - Strip `#[MeiliIndex]`, meili-bundle's `Facet`/`Fields` metadata and `FacetsFieldSearchFilter`
     from entities. Keep the `*_FIELDS` constants.
   - Delete Meili-only controllers (Meili dashboards extending `AbstractMeiliController`, app-local
     Meili proxies, `app:meili`-style commands), `templates/js/*` hit templates, embedder
     `.liquid` templates, and a `meili` process in the `Procfile`.
   - Remove any app entity that exists only to list Meili indexes (packages had `Endpoint`).
   - Add a migration for the tables that go with them (`DROP TABLE IF EXISTS index_info`, etc.).
   - `grep -ri meili src templates config assets .env castor.php` should come back empty apart from
     the generated `config/reference.php`.

   Watch composer's removal list. Removing meili-bundle from packages also removed
   `survos/jsonl-bundle`, which the installed elastic-bundle was about to need. Update `survos/*`
   in the same step.

5. **Crawl.** `bin/console survos:crawl` stops at the first 500, so fix and rerun until it finishes.
   Problems it found in these migrations, none of them about search:

   - A 500 on any `/meili/*` route: api-grid-bundle used to ship a stale `MeiliController` whose
     routes the kit route loader registered even without Meili. Removed in the 2.29.1 release.
   - `/admin/messenger/schedule` needs symfony/scheduler. Ignore the
     `zenstruck_messenger_monitor_schedule` route unless the app uses the scheduler.
   - EasyAdmin `/admin/<entity>/autocomplete` needs form parameters. Add
     `'^/admin/[a-z_]+/autocomplete$'` to `paths_to_ignore`.
   - auth-bundle routes 500 without an `App\Entity\User`. Set `survos_auth.routes_enabled: false`.
   - The state-bundle workflow page linked to `survos_command` without checking the route exists.
     Fixed in 2.29.2.
   - Old demo pages calling Twig functions no installed bundle defines. Delete them.

## Production on fsn1

The Elasticsearch service is in `survos/docker` → `elasticsearch/`, installed at `/opt/elasticsearch`.
It has **no public port or domain**. Apps reach it as `https://elasticsearch:9200` on the Docker
network `survos-es-private`, over TLS signed by a private CA. Everything below except the deploy
itself was done from the Mac, using `fsn1` (dokku) and `fsn1-root` (root).

1. **Keys.** Add the app to `APPS` in `provision.py` (app name → index prefix), then as root:

   ```bash
   scp provision.py connect-app.sh fsn1-root:/opt/elasticsearch/
   ssh fsn1-root 'cd /opt/elasticsearch && python3 provision.py && ./connect-app.sh packages'
   ```

   `provision.py` creates a search key and an indexer key per app, confined to its prefix, and
   verifies that each indexer key is refused on the other apps' prefixes. Existing keys are kept.
   `connect-app.sh` sets `ELASTICSEARCH_DSN` from the indexer key without printing it, URL-encoded
   because a base64 key can contain `+`.

2. **Network, CA and config** (as dokku):

   ```bash
   ssh fsn1 network:set packages attach-post-deploy survos-es-private
   ssh fsn1 storage:mount packages /opt/elasticsearch/certs/ca.crt:/etc/ssl/survos-es/ca.crt
   ssh fsn1 config:set --no-restart packages SEARCH_ADAPTER=es SEARCH_INDEX_PREFIX=packages_
   ```

   The resulting DSN is
   `elasticsearch+https://elasticsearch:9200?api_key=…&ca=/etc/ssl/survos-es/ca.crt`. `&ca=` needs
   search-bundle 2.29.2 or later; older releases ignore it and fail certificate verification.
   Never work around that by disabling verification.

3. **Restart policy.** `messenger:consume --time-limit` exits 0, and Dokku's default
   `on-failure` policy doesn't restart a clean exit, so workers silently stop an hour after deploy:

   ```bash
   ssh fsn1 ps:set packages restart-policy unless-stopped
   ```

4. **Deploy** with `git push dokku main`. The predeploy runs migrations.

5. **Build the index inside a process container.** `dokku run` starts a new container that is *not*
   on `survos-es-private` (it fails with *Could not resolve host: elasticsearch* / *No alive
   nodes*), because `attach-post-deploy` only applies to deployed process containers. Dokku also
   refuses to set the same network as both post-create and post-deploy. Run the rebuild in the
   worker instead:

   ```bash
   ssh fsn1 enter packages elastic php bin/console elastic:index:rebuild app_package -n --no-debug
   ssh fsn1 ps:scale packages elastic=1
   ```

   Until the index exists, the search page 500s with `index_not_found_exception`. That error at
   least proves TLS, network and key all work.

6. **Remove the Meili config** once the new code is live (not before: the old code requires it):

   ```bash
   ssh fsn1 config:unset packages MEILI_ADMIN_KEY MEILI_API_KEY MEILI_CHAT_KEY MEILI_READONLY_KEY MEILI_SEARCH_KEY MEILI_SERVER
   ```

### The API key in the logs

elastic/transport logs every request's headers at debug level, `Authorization: ApiKey …`
included. packages logs at debug in production, so its first searches wrote the key into the
fsn1 logs. search-bundle now wraps the transport's logger in `RedactingLogger` (the release after
2.29.2). After deploying that, rotate any key that was used before it: invalidate the app's
keys, delete `secrets/<app>-*.json` and rerun `provision.py` and `connect-app.sh`.

## One key or two

`connect-app.sh` gives the app its indexer key, which can also search. Web and workers share one
Dokku config, and search-bundle takes one DSN per adapter. The key still reaches only the app's
own prefix. Splitting a read-only key off for web requests needs separate read and write adapters.

## Status (2026-09-15)

| App | State |
|---|---|
| bench | Meili removed, local only (`es` branch). |
| packages | Meili removed and deployed; production index build and key rotation pending. |
| kpa | Baseline tagged; next. |
| global-giving | Keeps Meilisearch for its catalog; its Elasticsearch comparison lab uses the `gg_compare_*` keys. |
| ff, harvest, repo, … | Still on Meilisearch, several through api-grid's `MeiliSearchStateProvider`. Migrating those means replacing that provider too. |
