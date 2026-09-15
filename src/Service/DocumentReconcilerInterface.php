<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Service;

/** Brings the index in line with current database state for a set of entity ids. */
interface DocumentReconcilerInterface
{
    /**
     * Rows that exist are (re)indexed; ids with no row are deleted from the index.
     *
     * @param class-string $class
     * @param list<int|string> $ids
     * @return int documents indexed
     */
    public function indexIds(string $class, array $ids): int;
}
