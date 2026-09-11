<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Vector\VectorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ArchiveSearchTool implements RagTool
{
    private const RESULT_LIMIT = 5;
    private const EXCERPT_CHARS = 400;

    public function __construct(private readonly LlmClientInterface $llm)
    {
    }

    public function name(): string
    {
        return 'search_unstructured_archive';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => "Semantic search over this website's own content (posts/pages) for passages relevant to a query.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to search for.'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required.'];
        }

        try {
            $embeddings = $this->llm->embeddings([$query], RagSettings::embeddingModel());
        } catch (\Throwable $e) {
            return ['error' => 'Search unavailable: ' . $e->getMessage()];
        }

        if ($embeddings === []) {
            return ['error' => 'Search unavailable: no embedding returned.'];
        }

        Storage::ensureProtectedDir(Storage::dir());
        $repository = new VectorRepository(Storage::openSqlite(Storage::dbPath('taw_vectors.sqlite')));
        $matches = $repository->search($embeddings[0], self::RESULT_LIMIT);

        if ($matches === []) {
            return ['error' => 'No matching content found.'];
        }

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'post_id' => $match['post_id'],
                'permalink' => get_permalink($match['post_id']) ?: '',
                'excerpt' => mb_substr($match['content'], 0, self::EXCERPT_CHARS),
                'score' => round($match['score'], 4),
            ];
        }

        return ['results' => $results];
    }
}
