<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Spool;

use Psr\Log\LoggerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Durable reconciliation IDs; writes arriving during a drain remain for the next drain. */
final class ElasticSpooler
{
    public function __construct(private readonly string $spoolDir, private readonly ?LoggerInterface $logger = null) {}

    public function pathFor(string $entityClass): string
    {
        return rtrim($this->spoolDir, '/') . '/' . str_replace('\\', '.', $entityClass) . '.ids.jsonl';
    }

    /** @param list<int|string> $ids */
    public function appendIds(string $entityClass, array $ids): string
    {
        $path = $this->pathFor($entityClass);
        $this->locked($entityClass, function () use ($path, $ids): void {
            $writer = JsonlWriter::open($path, 'a');
            try {
                foreach ($ids as $id) { $writer->write(['id' => (string) $id]); }
            } finally { $writer->close(); }
        });
        return $path;
    }

    /** @return list<string> */
    public function pendingIds(string $entityClass): array
    {
        return $this->readIds([$this->pathFor($entityClass).'.processing', $this->pathFor($entityClass)]);
    }

    /** The callback must succeed before its claimed IDs are acknowledged. */
    public function drain(string $entityClass, callable $consume): void
    {
        $this->locked($entityClass.'-drain', function () use ($entityClass, $consume): void {
            $path = $this->pathFor($entityClass);
            $processing = $path.'.processing';
            // Resume an interrupted drain first, without merging or truncating newer writes.
            if (is_file($processing)) {
                $consume($this->readIds([$processing]));
                (new Filesystem())->remove($processing);
            }
            $this->locked($entityClass, static function () use ($path, $processing): void {
                if (is_file($path)) { (new Filesystem())->rename($path, $processing); }
            });
            if (is_file($processing)) {
                $consume($this->readIds([$processing]));
                (new Filesystem())->remove($processing);
            }
        });
    }

    public function clear(string $entityClass): void
    {
        $this->locked($entityClass, fn () => (new Filesystem())->remove($this->pathFor($entityClass)));
    }

    /** @return list<class-string> */
    public function spooledClasses(): array
    {
        if (!is_dir($this->spoolDir)) { return []; }
        $classes = [];
        foreach ((new Finder())->files()->in($this->spoolDir)->depth(0)->name('/\.ids\.jsonl(?:\.processing)?$/') as $file) {
            $name = preg_replace('/\.ids\.jsonl(?:\.processing)?$/', '', $file->getFilename());
            $classes[str_replace('.', '\\', $name)] = true;
        }
        return array_keys($classes);
    }

    private function locked(string $key, callable $operation): mixed
    {
        (new Filesystem())->mkdir($this->spoolDir);
        $lock = (new LockFactory(new FlockStore($this->spoolDir)))->createLock($key);
        $lock->acquire(true);
        try { return $operation(); } finally { $lock->release(); }
    }

    /** @param list<string> $paths @return list<string> */
    private function readIds(array $paths): array
    {
        $ids = [];
        foreach ($paths as $path) {
            if (!is_file($path)) { continue; }
            foreach (JsonlReader::open($path) as $row) {
                if (isset($row['id'])) { $ids[(string) $row['id']] = true; }
            }
        }
        return array_map(strval(...), array_keys($ids));
    }
}
