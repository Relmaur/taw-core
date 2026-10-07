<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

use TAW\Core\Corpus\CanonLaw\CanonLawEditions;
use TAW\Core\Rest\CanonLawEndpoint;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Structured lookup over an installed Code of Canon Law — the one
 * schema-specific tool next to {@see SearchKnowledgeBaseTool}, kept for
 * two reasons the generic knowledge-base path can't cover:
 *
 *  - a canon is cited by its number ("c. 1055 § 1"), and the generic
 *    SQLite ingestor embeds TEXT-affinity columns only — the integer
 *    `number` column would be dropped, so the model could never cite;
 *  - it reads through {@see CanonLawEndpoint::reader()}, which falls back
 *    to MySQL, so it works on hosts without `pdo_sqlite`, where the
 *    knowledge-base vector store can't run at all.
 *
 * Registered by {@see \TAW\Core\Rest\RagChatEndpoint} only when
 * {@see CanonLawEndpoint} is enabled and an edition is installed.
 */
final class CanonLawLookupTool implements RagTool
{
    private const SEARCH_LIMIT = 6;
    private const MAX_NUMBERS = 10;

    public function name(): string
    {
        return 'lookup_canon_law';
    }

    /**
     * The first registered edition that's installed, or null.
     */
    public static function installedEdition(): ?string
    {
        foreach (CanonLawEditions::all() as $edition) {
            if (CanonLawEndpoint::corpusInstalled($edition['slug'])) {
                return $edition['slug'];
            }
        }

        return null;
    }

    public function definition(): array
    {
        $edition = self::installedEdition();
        $name = $edition !== null ? (string) CanonLawEditions::name($edition) : 'Code of Canon Law';

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => "Look up canons of the {$name} — by canon number for the exact text, or by keywords "
                    . '(Spanish) to find the relevant canons. Cite canons as "c. N" in the answer. Some canons were amended '
                    . 'after 1983; results flag them and carry the original wording when the source has it.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'numbers' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Canon numbers to fetch (max ' . self::MAX_NUMBERS . ').',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Spanish keywords to search canon text for, when the numbers are unknown.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $edition = self::installedEdition();
        if ($edition === null) {
            return ['error' => 'No Code of Canon Law is installed.'];
        }

        $numbers = is_array($arguments['numbers'] ?? null)
            ? array_slice(array_map('intval', $arguments['numbers']), 0, self::MAX_NUMBERS)
            : [];
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($numbers === [] && $query === '') {
            return ['error' => 'Provide canon numbers or a search query.'];
        }

        $reader = CanonLawEndpoint::reader($edition);

        if ($numbers === []) {
            $hits = $reader->searchCanons($edition, $query, self::SEARCH_LIMIT);
            $numbers = array_column($hits, 'number');
            if ($numbers === []) {
                return ['edition' => CanonLawEditions::name($edition), 'canons' => [], 'note' => 'No canon matched that query.'];
            }
        }

        $canons = array_map(
            static fn (array $c): array => array_filter([
                'canon' => $c['number'],
                'location' => implode(' › ', array_column($c['breadcrumb'], 'title')),
                'text' => $c['text'],
                'amended' => $c['amended'] ?: null,
                'amendment' => $c['amendment']['note'] ?? null,
                'original_text' => $c['amendment']['original_text'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
            $reader->canons($edition, $numbers)
        );

        return ['edition' => CanonLawEditions::name($edition), 'canons' => $canons];
    }
}
