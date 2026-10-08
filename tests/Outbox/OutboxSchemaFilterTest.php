<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Outbox;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Outbox\OutboxSchemaFilter;

final class OutboxSchemaFilterTest extends TestCase
{
    public function testSchemaFilterHidesOnlyTheOutbox(): void
    {
        $filter = new OutboxSchemaFilter();
        self::assertFalse($filter('elastic_outbox'));
        self::assertFalse($filter('public.elastic_outbox'));
        self::assertTrue($filter('item'));
    }
}
