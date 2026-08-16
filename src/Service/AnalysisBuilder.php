<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Service;

/**
 * Builds the `analysis` settings block and applies the resulting analyzer to text fields.
 *
 * This is what makes Elasticsearch search *well* rather than merely work. Out of the box every
 * text field uses the `standard` analyzer, which lowercases and splits on word boundaries and
 * does nothing else — so "running" does not match "run", "Kovács" does not match "Kovacs", and
 * any comparison against Meilisearch (which stems by default) is unfair to Elasticsearch.
 *
 * `index.analysis` is a **static** setting: it can only be set when the index is created. Changing
 * the language therefore means a new generation and an alias swap, which is exactly why aliases
 * (survos/mono#42 item 1) had to land first.
 *
 * Deliberately narrow. Stopword removal is not offered: it breaks phrase queries ("to be or not to
 * be" becomes empty) and the disk it saves stopped mattering years ago.
 */
final readonly class AnalysisBuilder
{
    /** The analyzer every text field gets when analysis is configured. */
    public const string ANALYZER = 'survos_text';

    public const string STEMMER_FILTER = 'survos_stemmer';

    public function __construct(
        private ?string $language = null,
        private bool $asciiFolding = true,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->language && '' !== $this->language;
    }

    /**
     * The `settings` body for index creation, or an empty array when analysis is not configured.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        // Order matters: lowercase before folding so the folder sees consistent case, and stemming
        // last so it operates on already-normalised tokens.
        $filters = ['lowercase'];
        if ($this->asciiFolding) {
            $filters[] = 'asciifolding';
        }
        $filters[] = self::STEMMER_FILTER;

        return [
            'analysis' => [
                'filter' => [
                    self::STEMMER_FILTER => ['type' => 'stemmer', 'language' => $this->language],
                ],
                'analyzer' => [
                    self::ANALYZER => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => $filters,
                    ],
                ],
            ],
        ];
    }

    /**
     * Points every `text` field at the custom analyzer, leaving keyword/numeric/date fields alone.
     *
     * A `.keyword` subfield must stay unanalysed — it is what facets and sorts aggregate on, and
     * stemming it would turn "Running Shoes" into a bucket called "run shoe".
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $properties): array
    {
        if (!$this->isConfigured()) {
            return $properties;
        }

        foreach ($properties as $field => $definition) {
            if (!\is_array($definition) || 'text' !== ($definition['type'] ?? null)) {
                continue;
            }

            $definition['analyzer'] = self::ANALYZER;
            $properties[$field] = $definition;
        }

        return $properties;
    }

    public function language(): ?string
    {
        return $this->language;
    }
}
