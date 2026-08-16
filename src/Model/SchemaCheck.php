<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Model;

/**
 * One diagnostic row on the index detail page.
 *
 * `$issue` is the checklist number in survos/mono#42, so a warning points at the work that would
 * clear it instead of just complaining.
 */
final class SchemaCheck
{
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?int $issue = null,
    ) {
    }

    public static function ok(string $id, string $label, string $detail): self
    {
        return new self($id, $label, 'ok', $detail);
    }

    public static function warn(string $id, string $label, string $detail, ?int $issue = null): self
    {
        return new self($id, $label, 'warn', $detail, $issue);
    }

    public static function na(string $id, string $label, string $detail): self
    {
        return new self($id, $label, 'na', $detail);
    }

    public bool $isWarning {
        get => 'warn' === $this->status;
    }

    /** Tabler badge colour. */
    public string $color {
        get => match ($this->status) {
            'ok' => 'green',
            'warn' => 'yellow',
            default => 'secondary',
        };
    }

    public ?string $issueUrl {
        get => null === $this->issue ? null : 'https://github.com/survos/mono/issues/42';
    }
}
