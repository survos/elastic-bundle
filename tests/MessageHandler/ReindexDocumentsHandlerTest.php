<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\MessageHandler;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Message\ReindexDocuments;
use Survos\ElasticBundle\MessageHandler\ReindexDocumentsHandler;
use Survos\ElasticBundle\Service\DocumentReconcilerInterface;
use Symfony\Component\Messenger\Handler\Acknowledger;

final class ReindexDocumentsHandlerTest extends TestCase
{
    public function testBatchCollapsesMessagesIntoOneReconcilePerClass(): void
    {
        $reconciler = new class implements DocumentReconcilerInterface {
            public array $calls = [];
            public function indexIds(string $class, array $ids): int { $this->calls[] = [$class, $ids]; return count($ids); }
        };
        $handler = new ReindexDocumentsHandler($reconciler, batchSize: 3, idleTimeout: 0);

        $acked = [];
        $ack = static function (string $id) use (&$acked): Acknowledger {
            return new Acknowledger('test', static function (?\Throwable $e, mixed $result) use ($id, &$acked): void { $acked[$id] = $e ?? $result; });
        };

        self::assertSame(1, $handler(new ReindexDocuments('App\Asset', ['a1']), $ack('m1')));
        self::assertSame(2, $handler(new ReindexDocuments('App\Asset', ['a1', 'a2']), $ack('m2')));
        self::assertSame(0, $handler(new ReindexDocuments('App\Record', ['r1']), $ack('m3')));

        self::assertSame([['App\Asset', ['a1', 'a2']], ['App\Record', ['r1']]], $reconciler->calls);
        self::assertSame(['m1' => 2, 'm2' => 2, 'm3' => 1], $acked);
    }

    public function testFailureNacksOnlyThatClassGroup(): void
    {
        $reconciler = new class implements DocumentReconcilerInterface {
            public function indexIds(string $class, array $ids): int
            {
                if ($class === 'App\Broken') { throw new \RuntimeException('index missing'); }
                return count($ids);
            }
        };
        $handler = new ReindexDocumentsHandler($reconciler, batchSize: 2, idleTimeout: 0);

        $results = [];
        $ack = static function (string $id) use (&$results): Acknowledger {
            return new Acknowledger('test', static function (?\Throwable $e, mixed $r) use ($id, &$results): void { $results[$id] = $e?->getMessage() ?? $r; });
        };

        $handler(new ReindexDocuments('App\Broken', ['x']), $ack('bad'));
        $handler(new ReindexDocuments('App\Asset', ['a']), $ack('good'));

        self::assertSame(['bad' => 'index missing', 'good' => 1], $results);
    }

    public function testWithoutAcknowledgerHandlesSynchronously(): void
    {
        $reconciler = new class implements DocumentReconcilerInterface {
            public function indexIds(string $class, array $ids): int { return count($ids); }
        };

        self::assertSame(2, (new ReindexDocumentsHandler($reconciler))(new ReindexDocuments('App\Asset', ['a', 'b'])));
    }
}
