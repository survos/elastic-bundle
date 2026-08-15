<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Spool;

use Psr\Log\LoggerInterface;

/**
 * Append-only record of entity ids awaiting reindex.
 *
 * Intentionally the same shape as meili-bundle's JsonlSpooler -- one line per id, de-duplicated
 * by the writer, one file per entity class. The duplication is knowing: lifting a shared spooler
 * into search-bundle (or jsonl-bundle) is the right end state, but that's a change to a bundle
 * meili already depends on, so it shouldn't ride along with this split.
 */
final class ElasticSpooler
{
    public function __construct(
        private readonly string $spoolDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function pathFor(string $entityClass): string
    {
        return rtrim($this->spoolDir, '/') . '/' . str_replace('\\', '.', $entityClass) . '.ids.jsonl';
    }

    /** @param list<int|string> $ids */
    public function appendIds(string $entityClass, array $ids): string
    {
        $this->ensureDir();
        $path = $this->pathFor($entityClass);

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open spool file "%s".', $path));
        }
        try {
            foreach ($ids as $id) {
                fwrite($handle, json_encode(['id' => $id], JSON_UNESCAPED_SLASHES) . "\n");
            }
        } finally {
            fclose($handle);
        }

        $this->logger?->debug('Spooled ids', ['file' => $path, 'count' => count($ids)]);

        return $path;
    }

    /**
     * Ids awaiting reindex, de-duplicated -- the same entity flushed four times during an
     * import is one reconciliation, not four.
     *
     * @return list<string>
     */
    public function pendingIds(string $entityClass): array
    {
        $path = $this->pathFor($entityClass);
        if (!is_file($path)) {
            return [];
        }

        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['id'])) {
                $ids[(string) $decoded['id']] = true;
            }
        }

        return array_keys($ids);
    }

    public function clear(string $entityClass): void
    {
        $path = $this->pathFor($entityClass);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return list<class-string> */
    public function spooledClasses(): array
    {
        if (!is_dir($this->spoolDir)) {
            return [];
        }

        $classes = [];
        foreach (glob(rtrim($this->spoolDir, '/') . '/*.ids.jsonl') ?: [] as $file) {
            $classes[] = str_replace('.', '\\', basename($file, '.ids.jsonl'));
        }

        return $classes;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->spoolDir) && !@mkdir($this->spoolDir, 0775, true) && !is_dir($this->spoolDir)) {
            throw new \RuntimeException(sprintf('Cannot create spool dir "%s".', $this->spoolDir));
        }
    }
}
