<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Spool;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Symfony\Component\Filesystem\Filesystem;

final class ElasticSpoolerTest extends TestCase
{
    private string $directory;
    private ElasticSpooler $spool;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/elastic-spool-test-'.bin2hex(random_bytes(8));
        $this->spool = new ElasticSpooler($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testConcurrentAppendSurvivesDrain(): void
    {
        $this->spool->appendIds('App\\Entity\\Movie', [1, 1, 2]);
        $this->spool->drain('App\\Entity\\Movie', function (array $ids): void {
            self::assertSame(['1', '2'], $ids);
            $this->spool->appendIds('App\\Entity\\Movie', [3]);
        });
        self::assertSame(['3'], $this->spool->pendingIds('App\\Entity\\Movie'));
    }

    public function testFailedClaimIsRetriedBeforeNewWrites(): void
    {
        $this->spool->appendIds('App\\Entity\\Movie', ['old']);
        try {
            $this->spool->drain('App\\Entity\\Movie', static fn () => throw new \RuntimeException('offline'));
            self::fail('Drain must propagate failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('offline', $error->getMessage());
        }
        self::assertSame(['App\\Entity\\Movie'], $this->spool->spooledClasses());
        $this->spool->appendIds('App\\Entity\\Movie', ['new']);
        $batches = [];
        $this->spool->drain('App\\Entity\\Movie', static function (array $ids) use (&$batches): void { $batches[] = $ids; });
        self::assertSame([['old'], ['new']], $batches);
        self::assertSame([], $this->spool->pendingIds('App\\Entity\\Movie'));
        self::assertSame([], $this->spool->spooledClasses());
    }
}
