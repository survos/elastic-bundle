<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Message;

/**
 * "These documents need reconciliation."
 *
 * Carries ids, never documents. The worker loads current state when it runs, so duplicate
 * messages are cheap and correct: an import that flushes the same entity four times produces
 * four messages and one reindex from the final state, rather than four indexing operations
 * racing to write intermediate versions.
 *
 * @param class-string $entityClass
 * @param list<int|string> $ids
 */
final readonly class ReindexDocuments
{
    public function __construct(
        public string $entityClass,
        public array $ids,
    ) {}
}
