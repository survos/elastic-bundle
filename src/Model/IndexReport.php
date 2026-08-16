<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Model;

/** Everything the admin pages know about one search's Elasticsearch index, read live. */
final class IndexReport
{
    /**
     * @param list<string>         $aliases
     * @param array<string, mixed> $declaredFields the mapping the search declares
     * @param array<string, mixed> $actualFields   the mapping Elasticsearch holds
     * @param array<string, mixed> $settings       flat index settings
     * @param list<SchemaCheck>    $checks
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $entityClass,
        public readonly string $index,
        public readonly bool $exists,
        public readonly array $aliases = [],
        public readonly array $declaredFields = [],
        public readonly array $actualFields = [],
        public readonly array $settings = [],
        public readonly int $documentCount = 0,
        public readonly int $storeSizeBytes = 0,
        public readonly array $checks = [],
    ) {
    }

    public int $warningCount {
        get => \count(array_filter($this->checks, static fn (SchemaCheck $c): bool => $c->isWarning));
    }

    public ?string $shortClass {
        get => null === $this->entityClass ? null : (new \ReflectionClass($this->entityClass))->getShortName();
    }

    public string $shards {
        get => sprintf(
            '%s / %s',
            $this->settings['index.number_of_shards'] ?? '?',
            $this->settings['index.number_of_replicas'] ?? '?',
        );
    }

    /**
     * Every field, declared or not, with both types — the table body for the schema page.
     *
     * @return list<array{field: string, declared: ?string, actual: ?string, status: string}>
     */
    public array $fieldComparison {
        get {
            $names = array_unique([...array_keys($this->declaredFields), ...array_keys($this->actualFields)]);
            sort($names);

            $rows = [];
            foreach ($names as $name) {
                $declared = $this->typeOf($this->declaredFields[$name] ?? null);
                $actual = $this->typeOf($this->actualFields[$name] ?? null);

                $rows[] = [
                    'field' => $name,
                    'declared' => $declared,
                    'actual' => $actual,
                    'status' => match (true) {
                        null === $actual => 'missing',
                        null === $declared => 'undeclared',
                        $declared !== $actual => 'mismatch',
                        default => 'ok',
                    },
                ];
            }

            return $rows;
        }
    }

    private function typeOf(mixed $definition): ?string
    {
        return \is_array($definition) ? ($definition['type'] ?? null) : null;
    }
}
