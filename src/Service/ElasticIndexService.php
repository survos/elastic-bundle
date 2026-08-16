<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Model\IndexReport;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Symfony\Component\Messenger\MessageBusInterface;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Service\ElasticIndexNameResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Owns the Elasticsearch index lifecycle.
 *
 * The mapping itself is NOT computed here: it comes from the search's resolved adapter
 * parameters in survos/search-bundle, so querying and indexing can't drift apart. This
 * service only creates, fills, and reports on the index.
 */
final class ElasticIndexService
{
    public function __construct(
        private readonly UxSearchRegistry $registry,
        private readonly SearchProvider $searchProvider,
        private readonly AdapterProvider $adapterProvider,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ElasticSpooler $spooler,
        private readonly ElasticIndexInspector $inspector,
        private readonly ElasticIndexNameResolver $nameResolver,
        private readonly ?string $indexPattern = null,
        private readonly ?MessageBusInterface $bus = null,
    ) {}

    #[AsCommand('elastic:index:create', 'Create the Elasticsearch index and mapping for a search')]
    public function createCommand(
        SymfonyStyle $io,
        #[Argument('Search code; omit for every registered search')]
        ?string $code = null,
        #[Option('Drop the index first')]
        bool $drop = false,
    ): int {
        foreach ($this->resolve($io, $code) as [$descriptor, $client, $parameters, $search]) {
            $index = $this->nameResolver->uid($search);
            if ($drop && $client->indexExists($index)) {
                $client->deleteIndex($index);
                $io->note(sprintf('dropped %s', $index));
            }
            if ($client->indexExists($index)) {
                $io->text(sprintf('%s already exists', $index));
                continue;
            }
            $client->createIndex($index, $parameters['mappings'] ?? []);
            $io->success(sprintf('%s: created %s with %d mapped fields', $descriptor->code, $index, count($parameters['mappings'] ?? [])));
        }

        return Command::SUCCESS;
    }

    #[AsCommand('elastic:index:populate', 'Bulk-load documents into the Elasticsearch index')]
    public function populateCommand(
        SymfonyStyle $io,
        #[Argument('Search code; omit for every registered search')]
        ?string $code = null,
        #[Option('Documents per bulk request')]
        int $batchSize = 250,
        #[Option('Stop after this many documents')]
        ?int $limit = null,
    ): int {
        foreach ($this->resolve($io, $code) as [$descriptor, $client, $parameters, $search]) {
            $index = $this->nameResolver->uid($search);
            if (!$client->indexExists($index)) {
                $io->warning(sprintf('%s: index "%s" does not exist -- run elastic:index:create first.', $descriptor->code, $index));
                continue;
            }

            $count = $this->bulkIndex($client, $index, $this->documents($descriptor->class, $parameters, $limit), max(1, $batchSize));
            $io->success(sprintf('%s: indexed %d documents into %s', $descriptor->code, $count, $index));
        }

        return Command::SUCCESS;
    }

    #[AsCommand('elastic:index:status', 'Report index existence and document counts')]
    public function statusCommand(
        SymfonyStyle $io,
        #[Argument('Search code; omit for every registered search')]
        ?string $code = null,
    ): int {
        $rows = [];
        foreach ($this->resolve($io, $code) as [$descriptor, $client, $parameters, $search]) {
            $index = $this->nameResolver->uid($search);
            $exists = $client->indexExists($index);
            $docs = $exists ? ($client->search($index, ['size' => 0, 'track_total_hits' => true])['hits']['total']['value'] ?? 0) : 0;
            $rows[] = [$descriptor->code, $index, $exists ? 'yes' : 'no', $docs, count($parameters['mappings'] ?? [])];
        }

        $rows === []
            ? $io->warning('No Elasticsearch-backed searches are registered.')
            : $io->table(['search', 'index', 'exists', 'documents', 'mapped fields'], $rows);

        return Command::SUCCESS;
    }

    #[AsCommand('elastic:spool:flush', 'Reconcile the ids the Doctrine listener spooled')]
    public function spoolFlushCommand(
        SymfonyStyle $io,
        #[Argument('Entity FQCN; omit to drain every spooled class')]
        ?string $class = null,
        #[Option('Ids per batch')]
        int $batchSize = 500,
        #[Option('Dispatch through Messenger instead of indexing inline')]
        bool $async = false,
    ): int {
        $classes = $class !== null ? [$class] : $this->spooler->spooledClasses();
        if ($classes === []) {
            $io->success('Spool is empty.');

            return Command::SUCCESS;
        }

        foreach ($classes as $entityClass) {
            $ids = $this->spooler->pendingIds($entityClass);
            if ($ids === []) {
                continue;
            }

            $batches = 0;
            $indexed = 0;
            foreach (array_chunk($ids, max(1, $batchSize)) as $chunk) {
                ++$batches;
                if ($async) {
                    if ($this->bus === null) {
                        throw new \RuntimeException('--async needs symfony/messenger installed and a bus available.');
                    }
                    $this->bus->dispatch(new ReindexDocuments($entityClass, $chunk));
                    continue;
                }
                $indexed += $this->indexIds($entityClass, $chunk);
            }

            // Only clear once the work is safely handed off, so a crash mid-drain replays
            // rather than silently losing ids.
            $this->spooler->clear($entityClass);
            $io->success($async
                ? sprintf('%s: dispatched %d ids in %d batches', $entityClass, count($ids), $batches)
                : sprintf('%s: reconciled %d of %d spooled ids', $entityClass, $indexed, count($ids)));
        }

        return Command::SUCCESS;
    }

    /**
     * Reconcile a specific set of ids: load current state, rebuild those documents, bulk-index.
     *
     * This is what the Messenger handler calls. It is deliberately idempotent -- the message
     * carries ids, never documents, so duplicate messages are cheap and an entity flushed four
     * times during an import is reconciled once, from whatever state it ended up in.
     *
     * @param class-string $class
     * @param list<int|string> $ids
     */
    public function indexIds(string $class, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $resolved = $this->forEntityClass($class);
        if ($resolved === null) {
            return 0;
        }
        [$descriptor, $client, $parameters, $search] = $resolved;

        $manager = $this->managerRegistry->getManagerForClass($class);
        if ($manager === null) {
            return 0;
        }

        $entities = $manager->getRepository($class)->findBy([$this->identifierField($manager, $class) => $ids]);
        $parameters['documentProvider'] = $entities;

        return $this->bulkIndex(
            $client,
            $this->nameResolver->uid($search),
            $this->documents($class, $parameters, null),
            max(1, count($entities)),
        );
    }

    /**
     * Remove documents whose entities are gone. Ids only -- by the time the worker runs, the
     * rows no longer exist to be loaded.
     *
     * @param class-string $class
     * @param list<int|string> $ids
     */
    public function deleteIds(string $class, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $resolved = $this->forEntityClass($class);
        if ($resolved === null) {
            return 0;
        }
        [$descriptor, $client, $parameters, $search] = $resolved;

        $index = $this->nameResolver->uid($search);
        $body = [];
        foreach ($ids as $id) {
            $body[] = ['delete' => ['_index' => $index, '_id' => (string) $id]];
        }

        $response = $client->bulk($body);
        $client->refresh($index);

        // A delete for an id that was never indexed comes back as result=not_found, which is
        // fine and must not be treated as an error -- the spool is intentionally over-inclusive.
        foreach ($response['items'] ?? [] as $item) {
            $error = $item['delete']['error'] ?? null;
            if ($error !== null) {
                throw new \RuntimeException(sprintf(
                    'Elasticsearch rejected delete of "%s": %s',
                    $item['delete']['_id'] ?? '?',
                    json_encode($error, JSON_UNESCAPED_SLASHES),
                ));
            }
        }

        return count($ids);
    }

    /**
     * @param class-string $class
     * @return array{0: object, 1: ElasticsearchClientInterface, 2: array<string, mixed>}|null
     */
    public function forEntityClass(string $class): ?array
    {
        foreach ($this->registry->all() as $descriptor) {
            if ($descriptor->class !== $class) {
                continue;
            }

            $search = $this->searchProvider->getSearch($descriptor->name)->create([
                'hitTemplate' => $descriptor->hitTemplate,
            ]);
            $adapter = $this->adapterProvider->getAdapter($search->getAdapterName());
            $client = $this->clientOf($adapter);
            if ($client === null) {
                return null;
            }

            $resolver = new OptionsResolver();
            $adapter->configureParameters($resolver);
            $parameters = $resolver->resolve($search->getAdapterParameters());
            $search->setResolvedAdapterParameters($parameters);

            return [$descriptor, $client, $parameters, $search];
        }

        return null;
    }

    /** @param class-string $class */
    private function identifierField(object $manager, string $class): string
    {
        return $manager->getClassMetadata($class)->getSingleIdentifierFieldName();
    }

    /**
     * Live schema report for every Elasticsearch-backed search — the admin index page.
     *
     * @return list<IndexReport>
     */
    public function reports(): array
    {
        $client = null;
        $searches = [];
        foreach ($this->searches(null) as [$descriptor, $searchClient, $parameters, $search]) {
            $client ??= $searchClient;
            $searches[] = [$descriptor, $parameters, $search];
        }

        if (null === $client || [] === $searches) {
            return [];
        }

        $bulk = $this->bulkState($client);

        $reports = [];
        foreach ($searches as [$descriptor, $parameters, $search]) {
            $reports[] = $this->inspect($descriptor, $parameters, $bulk, $search);
        }

        return $reports;
    }

    /**
     * Is anything in this app backed by Elasticsearch?
     *
     * Registry and adapter resolution only — **no HTTP at all**. The admin navbar asks this on
     * every request, and Twig's `tabler_menu_has_items()` builds the menu once just to test
     * emptiness and again to render it, so the menu event fires several times per page. Anything
     * touching Elasticsearch from a menu subscriber gets multiplied by that.
     */
    public function hasElasticSearches(): bool
    {
        foreach ($this->searches(null) as $ignored) {
            return true;
        }

        return false;
    }

    /**
     * Every cluster index matching this app's pattern, each tagged with the search that declares
     * it — or null when nothing does.
     *
     * The registry only knows what the app declared. The cluster's index namespace is flat and
     * shared by every app pointed at the node, so an index this app owns can exist without any
     * search claiming it: a rename that left the old name behind, a locale variant, something
     * created by a command that has since changed. Those are exactly the ones worth seeing, and
     * they are invisible to reports().
     *
     * Dot-prefixed system indices are skipped — on this node they are the majority (Kibana's own)
     * and none of them belong to an app.
     *
     * @return list<array{index: string, health: ?string, status: ?string, docs: int, size: ?string, primaries: ?int, replicas: ?int, searchCode: ?string}>
     */
    public function clusterIndices(?string $pattern = null): array
    {
        $client = null;
        $declared = [];
        foreach ($this->searches(null) as [$descriptor, $searchClient, $parameters, $search]) {
            $client ??= $searchClient;
            $declared[$this->nameResolver->uid($search)] = $descriptor->code;
        }

        if (null === $client) {
            return [];
        }

        $rows = [];
        foreach ($client->listIndices($pattern ?? $this->indexPattern()) as $row) {
            if (str_starts_with($row['index'], '.')) {
                continue;
            }

            $row['searchCode'] = $declared[$row['index']] ?? null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The pattern covering this app's indices.
     *
     * Defaults to the resolver's own `<prefix>*`, so the admin page filters by exactly what the
     * app writes — an explicitly configured pattern is an override, not a second source of truth.
     */
    public function indexPattern(): string
    {
        return $this->indexPattern ?? $this->nameResolver->pattern();
    }

    /** Live schema report for one search, or null when it isn't registered or isn't ES-backed. */
    public function report(string $code): ?IndexReport
    {
        foreach ($this->searches($code) as [$descriptor, $client, $parameters, $search]) {
            $index = $this->nameResolver->uid($search);

            // Same four calls, narrowed to the one index, plus _stats for the real byte size.
            $bulk = $this->bulkState($client, $index);
            $bulk['docs'][$index]['size'] = (int) ($client->getStats($index)['store']['size_in_bytes'] ?? 0);

            return $this->inspect($descriptor, $parameters, $bulk, $search);
        }

        return null;
    }

    /**
     * One round of pattern-wide introspection, keyed by index name.
     *
     * `_mapping`, `_settings`, `_alias` and `_cat/indices` all accept an index pattern, so a page
     * covering N searches costs four requests rather than 5N.
     *
     * @return array{mappings: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, aliases: array<string, list<string>>, docs: array<string, array{docs: int, size: int}>}
     */
    private function bulkState(ElasticsearchClientInterface $client, ?string $pattern = null): array
    {
        $pattern ??= $this->indexPattern();

        $docs = [];
        foreach ($client->listIndices($pattern) as $row) {
            // _cat reports a human-readable size ("5.6mb"); the byte count needs _stats, which is
            // only worth a call on the detail page.
            $docs[$row['index']] = ['docs' => $row['docs'], 'size' => 0];
        }

        return [
            'mappings' => $client->listMappings($pattern),
            'settings' => $client->listSettings($pattern),
            'aliases' => $client->listAliases($pattern),
            'docs' => $docs,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $bulk
     */
    private function inspect(object $descriptor, array $parameters, array $bulk, SearchInterface $search): IndexReport
    {
        $index = $this->nameResolver->uid($search);
        $docs = $bulk['docs'][$index] ?? null;

        return $this->inspector->inspect(
            code: $descriptor->code,
            class: $descriptor->class ?? null,
            index: $index,
            // Presence in _cat/indices answers the same question indexExists() did, for free.
            exists: null !== $docs,
            mapping: $bulk['mappings'][$index] ?? [],
            settings: $bulk['settings'][$index] ?? [],
            aliases: $bulk['aliases'][$index] ?? [],
            stats: ['docs' => ['count' => $docs['docs'] ?? 0], 'store' => ['size_in_bytes' => $docs['size'] ?? 0]],
            parameters: $parameters,
        );
    }

    /** @return list<object> */
    private function descriptors(?string $code): array
    {
        return $code === null
            ? $this->registry->all()
            : array_values(array_filter(
                $this->registry->all(),
                static fn ($d): bool => $d->code === $code || $d->name === $code,
            ));
    }

    /**
     * @return \Generator<int, array{0: object, 1: ElasticsearchClientInterface, 2: array<string, mixed>}>
     */
    private function resolve(SymfonyStyle $io, ?string $code): \Generator
    {
        if ($this->descriptors($code) === []) {
            $io->warning($code === null ? 'No searches are registered.' : sprintf('No search registered for "%s".', $code));

            return;
        }

        yield from $this->searches($code);
    }

    /**
     * The same resolution as resolve(), without a console to complain to — the controllers need it
     * too, and an admin page must not depend on SymfonyStyle.
     *
     * @return \Generator<int, array{0: object, 1: ElasticsearchClientInterface, 2: array<string, mixed>}>
     */
    private function searches(?string $code): \Generator
    {
        foreach ($this->descriptors($code) as $descriptor) {
            $search = $this->searchProvider->getSearch($descriptor->name)->create([
                'hitTemplate' => $descriptor->hitTemplate,
            ]);
            $adapter = $this->adapterProvider->getAdapter($search->getAdapterName());
            $client = $this->clientOf($adapter);
            if ($client === null) {
                continue; // not Elasticsearch-backed; nothing for this bundle to do
            }

            $resolver = new OptionsResolver();
            $adapter->configureParameters($resolver);
            $parameters = $resolver->resolve($search->getAdapterParameters());
            // The query path resolves parameters onto the search itself; this CLI/admin path
            // resolved them into a local array only, so ElasticIndexNameResolver saw no `index`
            // parameter and fell back to the entity FQCN -- a different index from the one queries
            // hit. Put them back on the search so both paths read one source.
            $search->setResolvedAdapterParameters($parameters);

            // $search comes last so existing 3-element destructuring keeps working.
            yield [$descriptor, $client, $parameters, $search];
        }
    }

    /**
     * The adapter holds the configured client. Reach it reflectively rather than widening
     * search-bundle's read-only adapter contract with an accessor that only this bundle wants.
     */
    private function clientOf(object $adapter): ?ElasticsearchClientInterface
    {
        if (!$adapter instanceof \Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter) {
            return null;
        }

        $property = new \ReflectionProperty($adapter, 'client');

        return $property->getValue($adapter);
    }

    /** @param array<string, mixed> $parameters */

    /**
     * @param class-string $class
     * @param array<string, mixed> $parameters
     * @return \Generator<int, array{id: string, document: array<string, mixed>}>
     */
    private function documents(string $class, array $parameters, ?int $limit): \Generator
    {
        $fields = $parameters['sourceFields'] ?: $parameters['searchFields'];
        $idField = (string) $parameters['idField'];

        $provider = $parameters['documentProvider'] ?? null;
        if (is_callable($provider)) {
            $provider = $provider();
        }
        if (!is_iterable($provider)) {
            $manager = $this->managerRegistry->getManagerForClass($class)
                ?? throw new \LogicException(sprintf('No documentProvider configured and no Doctrine manager for %s.', $class));
            // toIterable(), not findAll(): a full hydration of the table into memory before the
            // first bulk request is the difference between indexing 14k rows and dying on 1M.
            $provider = $manager->createQuery(sprintf('SELECT e FROM %s e', $class))->toIterable();
        }

        $mapper = $parameters['documentMapper'] ?? null;
        $count = 0;
        foreach ($provider as $source) {
            if ($limit !== null && $count >= $limit) {
                return;
            }
            $document = is_callable($mapper) ? $mapper($source) : $this->mapDocument($source, $fields);
            if (!is_array($document)) {
                throw new \UnexpectedValueException('documentMapper must return an array.');
            }
            $document = array_map($this->normalize(...), $document);

            $id = $document[$idField] ?? $this->readValue($source, $idField);
            if (!is_scalar($id) && !$id instanceof \Stringable) {
                throw new \LogicException(sprintf('Unable to resolve scalar id field "%s".', $idField));
            }

            yield ['id' => (string) $id, 'document' => $document];
            ++$count;
        }
    }

    /**
     * @param iterable<array{id: string, document: array<string, mixed>}> $documents
     */
    public function bulkIndex(ElasticsearchClientInterface $client, string $index, iterable $documents, int $batchSize): int
    {
        $body = [];
        $count = 0;
        foreach ($documents as $item) {
            $body[] = ['index' => ['_index' => $index, '_id' => $item['id']]];
            $body[] = $item['document'];
            if ((++$count % $batchSize) === 0) {
                $this->flush($client, $body);
                $body = [];
            }
        }
        if ($body !== []) {
            $this->flush($client, $body);
        }
        if ($count > 0) {
            $client->refresh($index);
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $body */
    private function flush(ElasticsearchClientInterface $client, array $body): void
    {
        $response = $client->bulk($body);
        if (($response['errors'] ?? false) !== true) {
            return;
        }

        foreach ($response['items'] ?? [] as $item) {
            $error = $item['index']['error'] ?? null;
            if ($error !== null) {
                throw new \RuntimeException(sprintf(
                    'Elasticsearch rejected document "%s": %s',
                    $item['index']['_id'] ?? '?',
                    json_encode($error, JSON_UNESCAPED_SLASHES),
                ));
            }
        }
    }

    /**
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function mapDocument(mixed $source, array $fields): array
    {
        $document = [];
        foreach ($fields as $field) {
            $name = preg_replace('/^[a-z]+\./', '', $field) ?? $field;
            $document[$name] = $this->readValue($source, $name);
        }

        return $document;
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => (string) $value,
            is_array($value) => array_map($this->normalize(...), $value),
            default => $value,
        };
    }

    private function readValue(mixed $source, string $field): mixed
    {
        if (is_array($source)) {
            return $source[$field] ?? null;
        }
        if (!is_object($source)) {
            return null;
        }
        $rc = new \ReflectionClass($source);
        if ($rc->hasProperty($field)) {
            $property = $rc->getProperty($field);

            return $property->isInitialized($source) ? $property->getValue($source) : null;
        }
        foreach (['get', 'is', ''] as $prefix) {
            $method = $prefix === '' ? $field : $prefix . ucfirst($field);
            if ($rc->hasMethod($method)) {
                return $rc->getMethod($method)->invoke($source);
            }
        }

        return null;
    }
}
