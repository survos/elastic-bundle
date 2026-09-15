<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\EventListener\ElasticSpoolDoctrineListener;
use Survos\ElasticBundle\Spool\ElasticSpooler;
use Survos\SearchBundle\Model\UxSearchDescriptor;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final class ElasticSpoolDoctrineListenerTest extends TestCase
{
    private string $directory;
    private ElasticSpooler $spool;
    private EntityManagerInterface $manager;
    private UxSearchRegistry $registry;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/elastic-listener-'.bin2hex(random_bytes(8));
        $this->spool = new ElasticSpooler($this->directory);
        $metadata = new ClassMetadata(ListenerEntity::class);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->wakeupReflection(new \Doctrine\Persistence\Mapping\RuntimeReflectionService());
        $this->manager = $this->createStub(EntityManagerInterface::class);
        $this->manager->method('getClassMetadata')->willReturn($metadata);
        $this->registry = new UxSearchRegistry([new UxSearchDescriptor(ListenerEntity::class, 'test', 'test', 'es')]);
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->directory); }

    public function testDeletionKeepsIdWhenDoctrineClearsItBeforePostFlush(): void
    {
        $listener = new ElasticSpoolDoctrineListener($this->spool, $this->registry, async: false);
        $entity = new ListenerEntity();
        $listener->preRemove(new PreRemoveEventArgs($entity, $this->manager));
        $entity->id = null;
        $listener->postFlush(new PostFlushEventArgs($this->manager));
        self::assertSame(['42'], $this->spool->pendingIds(ListenerEntity::class));
    }

    public function testClearDiscardsEventsFromFailedFlush(): void
    {
        $listener = new ElasticSpoolDoctrineListener($this->spool, $this->registry, async: false);
        $listener->postPersist(new PostPersistEventArgs(new ListenerEntity(), $this->manager));
        $listener->onClear(new OnClearEventArgs($this->manager));
        $listener->postFlush(new PostFlushEventArgs($this->manager));
        self::assertSame([], $this->spool->pendingIds(ListenerEntity::class));
    }

    public function testQueueFailureRetainsIdsForRecovery(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('queue unavailable'));
        $listener = new ElasticSpoolDoctrineListener($this->spool, $this->registry, bus: $bus);
        $listener->postPersist(new PostPersistEventArgs(new ListenerEntity(), $this->manager));
        $listener->postFlush(new PostFlushEventArgs($this->manager));
        self::assertSame(['42'], $this->spool->pendingIds(ListenerEntity::class));
    }
}

final class ListenerEntity { public ?int $id = 42; }
