<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReindexDocumentsHandler
{
    public function __construct(
        private ElasticIndexService $indexService,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(ReindexDocuments $message): void
    {
        $count = $this->indexService->indexIds($message->entityClass, $message->ids);

        $this->logger?->info('Reindexed documents', [
            'class' => $message->entityClass,
            'requested' => count($message->ids),
            'indexed' => $count,
        ]);
    }
}
