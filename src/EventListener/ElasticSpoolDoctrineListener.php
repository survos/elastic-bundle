<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Message\RemoveDocuments;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records which entities changed, and hands them off. Never touches Elasticsearch inline.
 *
 * Mirrors meili-bundle's MeiliSpoolDoctrineListener. Ids are collected during
 * persist/update/remove and dispatched (or spooled) in postFlush, in batches. A database write
 * must not depend on the search engine being up, and an HTTP request must not wait on it.
 *
 * Two outputs, by design:
 *
 *  - Messenger, when a bus is available and async is on. Ids are chunked so one enormous flush
 *    becomes several bounded jobs rather than a single message a worker chokes on.
 *  - A JSONL spool otherwise, drained later by elastic:spool:flush. This is the right mode for
 *    a bulk import: writing a line per id costs nothing, and reconciling 400k ids once at the
 *    end beats dispatching 400k messages.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
#[AsDoctrineListener(Events::postFlush)]
final class ElasticSpoolDoctrineListener
{
    /** @var array<class-string, array<string, bool>> */
    private array $pendingIds = [];

    /** @var array<class-string, array<string, bool>> */
    private array $removedIds = [];

    public function __construct(
        private readonly ElasticSpooler $spooler,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
        private readonly bool $async = true,
        private readonly int $batchSize = 500,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args, $this->pendingIds);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args, $this->pendingIds);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        // postRemove still has the identifier in memory; after postFlush it is gone.
        $this->collect($args, $this->removedIds);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $pending = $this->pendingIds;
        $removed = $this->removedIds;
        // Reset BEFORE dispatching: a handler running synchronously (sync transport, or
        // messenger configured without async) can trigger another flush, and re-entering this
        // method with the buffers still full would dispatch the same ids again, forever.
        $this->pendingIds = [];
        $this->removedIds = [];

        foreach ($removed as $class => $map) {
            $this->emit($class, array_keys($map), true);
        }
        foreach ($pending as $class => $map) {
            // Anything deleted in the same flush must not also be reindexed.
            $ids = array_keys(array_diff_key($map, $removed[$class] ?? []));
            $this->emit($class, $ids, false);
        }
    }

    /** @param list<string> $ids */
    private function emit(string $class, array $ids, bool $removal): void
    {
        if ($ids === []) {
            return;
        }

        if ($this->bus === null || !$this->async) {
            if (!$removal) {
                $this->spooler->appendIds($class, $ids);
            }
            // Deletions cannot wait for a batch drain -- the row is already gone, so a spool
            // entry would be indistinguishable from a stale id. Fall through and let the
            // reconcile step notice, rather than silently dropping it.
            $this->logger?->debug('Elastic spooled ids', ['class' => $class, 'count' => count($ids), 'removal' => $removal]);

            return;
        }

        foreach (array_chunk($ids, max(1, $this->batchSize)) as $chunk) {
            $this->bus->dispatch($removal
                ? new RemoveDocuments($class, $chunk)
                : new ReindexDocuments($class, $chunk));
        }

        $this->logger?->debug('Elastic dispatched', [
            'class' => $class,
            'count' => count($ids),
            'batches' => (int) ceil(count($ids) / max(1, $this->batchSize)),
            'removal' => $removal,
        ]);
    }

    /** @param array<class-string, array<string, bool>> $bucket */
    private function collect(
        PostPersistEventArgs|PostUpdateEventArgs|PostRemoveEventArgs $args,
        array &$bucket,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $object = $args->getObject();
        $metadata = $args->getObjectManager()->getClassMetadata($object::class);
        $identifiers = $metadata->getIdentifierValues($object);
        if (count($identifiers) !== 1) {
            return; // composite keys have no single _id to map onto
        }

        $id = reset($identifiers);
        if ($id !== null) {
            $bucket[$object::class][(string) $id] = true;
        }
    }
}
