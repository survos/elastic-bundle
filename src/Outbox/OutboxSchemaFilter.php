<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Outbox;

use Doctrine\DBAL\Schema\AbstractAsset;

/**
 * Hides elastic_outbox from Doctrine's schema tools. The table is created by
 * elastic:index:create --triggers, not by a migration, so without this
 * doctrine:migrations:diff would generate a DROP TABLE for it.
 */
final class OutboxSchemaFilter
{
    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;
        $parts = explode('.', $name); // schema-qualified on some platforms

        return trim(end($parts), '"') !== ElasticOutbox::TABLE;
    }
}
