<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Spool\ElasticSpooler;

/**
 * Records which entities changed, and nothing else.
 *
 * Deliberately mirrors meili-bundle's MeiliSpoolDoctrineListener: ids are collected during
 * persist/update and appended to a spool file in postFlush. No Elasticsearch call, no
 * document build, no embedding happens here -- a database write must not depend on the
 * search engine being up, and an import that flushes the same entity four times must not
 * index it four times. The spool says "this document needs reconciliation"; the flush step
 * loads current state and decides what actually changed.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postFlush)]
final class ElasticSpoolDoctrineListener
{
    /** @var array<class-string, array<string, bool>> */
    private array $pendingIds = [];

    public function __construct(
        private readonly ElasticSpooler $spooler,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->pendingIds === []) {
            return;
        }

        foreach ($this->pendingIds as $class => $map) {
            $ids = array_keys($map);
            $path = $this->spooler->appendIds($class, $ids);
            $this->logger?->info('Elastic spooled ids', ['class' => $class, 'count' => count($ids), 'file' => $path]);
        }

        $this->pendingIds = [];
    }

    private function collect(PostPersistEventArgs|PostUpdateEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $object = $args->getObject();
        $id = $args->getObjectManager()->getClassMetadata($object::class)->getIdentifierValues($object)['id'] ?? null;
        if ($id !== null) {
            $this->pendingIds[$object::class][(string) $id] = true;
        }
    }
}
