<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\ElasticBundle\Message\RemoveDocuments;
use Survos\ElasticBundle\Service\ElasticIndexService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveDocumentsHandler
{
    public function __construct(
        private ElasticIndexService $indexService,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(RemoveDocuments $message): void
    {
        $count = $this->indexService->deleteIds($message->entityClass, $message->ids);

        $this->logger?->info('Removed documents', [
            'class' => $message->entityClass,
            'removed' => $count,
        ]);
    }
}
