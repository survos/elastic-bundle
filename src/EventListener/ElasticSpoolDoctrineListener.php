<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Collect identifiers, then reconcile current database state outside the write request. */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::preRemove)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::onClear)]
final class ElasticSpoolDoctrineListener implements ResetInterface
{
    /** @var array<int, array<class-string, array<string, true>>> */
    private array $pendingIds = [];

    /** @var array<class-string, bool> */
    private array $searchableClasses = [];

    public function __construct(
        private readonly ElasticSpooler $spooler,
        private readonly UxSearchRegistry $registry,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
        private readonly bool $async = true,
        private readonly int $batchSize = 500,
        private readonly ?ElasticIndexService $indexService = null,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void { $this->collect($args); }
    public function postUpdate(PostUpdateEventArgs $args): void { $this->collect($args); }

    // Doctrine clears generated identifiers before postRemove; capture them beforehand.
    public function preRemove(PreRemoveEventArgs $args): void { $this->collect($args); }

    public function onClear(OnClearEventArgs $args): void
    {
        unset($this->pendingIds[spl_object_id($args->getObjectManager())]);
    }

    public function reset(): void { $this->pendingIds = []; }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $managerId = spl_object_id($args->getObjectManager());
        $pending = $this->pendingIds[$managerId] ?? [];
        unset($this->pendingIds[$managerId]);
        foreach ($pending as $class => $map) {
            $ids = array_map(strval(...), array_keys($map));
            if ($this->bus === null || !$this->async) {
                $this->spooler->appendIds($class, $ids);
                continue;
            }
            try {
                foreach (array_chunk($ids, max(1, $this->batchSize)) as $chunk) {
                    // Deletes use the same reconciliation: absent rows are removed. A stale
                    // delete job cannot remove a row subsequently recreated with the same id.
                    $this->bus->dispatch(new ReindexDocuments($class, $chunk));
                }
            } catch (\Throwable $error) {
                // A queue outage must not silently lose a change after the database committed.
                $this->spooler->appendIds($class, $ids);
                $this->logger?->error('Elastic dispatch failed; reconciliation retained in spool.', ['exception' => $error]);
            }
        }
    }

    private function collect(PostPersistEventArgs|PostUpdateEventArgs|PreRemoveEventArgs $args): void
    {
        if (!$this->enabled) { return; }
        $manager = $args->getObjectManager();
        $metadata = $manager->getClassMetadata($args->getObject()::class);
        $class = $metadata->getName(); // resolves Doctrine proxy subclasses
        if (!array_key_exists($class, $this->searchableClasses)) {
            $this->searchableClasses[$class] = $this->indexService !== null
                ? $this->indexService->forEntityClass($class) !== null
                : $this->registry->forClass($class) !== null;
        }
        if (!$this->searchableClasses[$class]) { return; }
        $identifiers = $metadata->getIdentifierValues($args->getObject());
        if (count($identifiers) !== 1) { return; }
        $id = reset($identifiers);
        if ($id !== null) {
            $this->pendingIds[spl_object_id($manager)][$class][(string) $id] = true;
        }
    }
}
