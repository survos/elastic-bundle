<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Service\DocumentReconcilerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * A workflow flushes once per transition, and the Doctrine listener dispatches once per flush,
 * so a busy pipeline sends a stream of one-id messages. Batching them here turns that stream into
 * one database query and one bulk request per class, with the same id collapsed to a single
 * reindex from its latest state.
 *
 * The worker flushes a partial batch after the idle timeout, so a lone update still lands within
 * about a second.
 */
#[AsMessageHandler]
final class ReindexDocumentsHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private readonly DocumentReconcilerInterface $reconciler,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $batchSize = 50,
        private readonly int $idleTimeout = 1,
        private readonly int $idsPerRequest = 500,
    ) {}

    public function __invoke(ReindexDocuments $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /** @param list<array{0: ReindexDocuments, 1: Acknowledger}> $jobs */
    private function process(array $jobs): void
    {
        $byClass = [];
        foreach ($jobs as $job) {
            $byClass[$job[0]->entityClass][] = $job;
        }

        foreach ($byClass as $class => $classJobs) {
            $ids = [];
            foreach ($classJobs as [$message]) {
                foreach ($message->ids as $id) {
                    $ids[(string) $id] = true;
                }
            }
            $ids = array_keys($ids);

            try {
                $indexed = 0;
                foreach (array_chunk($ids, max(1, $this->idsPerRequest)) as $chunk) {
                    $indexed += $this->reconciler->indexIds($class, array_map(strval(...), $chunk));
                }
            } catch (\Throwable $error) {
                // The whole class group retries together; reconciliation is idempotent.
                foreach ($classJobs as [, $ack]) {
                    $ack->nack($error);
                }
                continue;
            }

            foreach ($classJobs as [, $ack]) {
                $ack->ack($indexed);
            }
            $this->logger?->info('Reindexed documents', [
                'class' => $class,
                'messages' => count($classJobs),
                'ids' => count($ids),
                'indexed' => $indexed,
            ]);
        }
    }

    private function getBatchSize(): int
    {
        return max(1, $this->batchSize);
    }

    private function getIdleTimeout(): ?int
    {
        return $this->idleTimeout > 0 ? $this->idleTimeout : null;
    }
}
