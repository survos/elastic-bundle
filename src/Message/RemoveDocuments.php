<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Message;

/**
 * Entities that were deleted, so their documents must go too.
 *
 * Separate from ReindexDocuments because by the time the worker runs there is no row left to
 * load -- the id is all the information that survives.
 *
 * @param class-string $entityClass
 * @param list<int|string> $ids
 */
final readonly class RemoveDocuments
{
    public function __construct(
        public string $entityClass,
        public array $ids,
    ) {}
}
