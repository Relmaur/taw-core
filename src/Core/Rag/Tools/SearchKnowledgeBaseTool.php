<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseRegistry;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Vector\VectorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one search tool — replaces the old BibleLookupTool/CatechismLookupTool/
 * ArchiveSearchTool trio. Every knowledge base (the site's own WP content,
 * plus any admin-uploaded .sqlite file) is searched the same way: semantic
 * similarity over pre-embedded chunks, no schema-specific lookup.
 */
final class SearchKnowledgeBaseTool implements RagTool
{
    private const RESULT_LIMIT = 5;
    private const EXCERPT_CHARS = 400;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly KnowledgeBaseRegistry $registry = new KnowledgeBaseRegistry(),
    ) {
    }

    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function definition(): array
    {
        $knowledgeBases = $this->registry->all();
        $ids = array_map(static fn (array $kb): string => $kb['id'], $knowledgeBases);
        $descriptions = array_map(
            static fn (array $kb): string => "- `{$kb['id']}`: {$kb['name']} — {$kb['description']}",
            $knowledgeBases
        );

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => "Semantic search over one of this site's knowledge bases. Available:\n" . implode("\n", $descriptions),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'knowledge_base' => [
                            'type' => 'string',
                            'enum' => $ids,
                            'description' => 'Which knowledge base to search.',
                        ],
                        'query' => ['type' => 'string', 'description' => 'What to search for.'],
                    ],
                    'required' => ['knowledge_base', 'query'],
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $id = trim((string) ($arguments['knowledge_base'] ?? ''));
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($id === '' || $query === '') {
            return ['error' => 'knowledge_base and query are required.'];
        }

        $kb = $this->registry->find($id);
        if ($kb === null) {
            return ['error' => "Unknown knowledge base '{$id}'."];
        }
        if ($kb['status'] !== 'ready') {
            return ['error' => "Knowledge base '{$id}' is not ready yet (status: {$kb['status']})."];
        }

        try {
            $embeddings = $this->llm->embeddings([$query], RagSettings::embeddingModel());
        } catch (\Throwable $e) {
            return ['error' => 'Search unavailable: ' . $e->getMessage()];
        }

        if ($embeddings === []) {
            return ['error' => 'Search unavailable: no embedding returned.'];
        }

        $isWpContent = $id === KnowledgeBaseRegistry::WP_CONTENT_ID;
        $filename = $isWpContent ? 'taw_vectors.sqlite' : (string) $kb['source_file'];

        Storage::ensureProtectedDir(Storage::dir());
        $repository = new VectorRepository(Storage::openSqlite(Storage::dbPath($filename)));
        $matches = $repository->search($embeddings[0], self::RESULT_LIMIT);

        if ($matches === []) {
            return ['error' => 'No matching content found.'];
        }

        $results = [];
        foreach ($matches as $match) {
            $result = [
                'excerpt' => mb_substr($match['content'], 0, self::EXCERPT_CHARS),
                'score' => round($match['score'], 4),
            ];
            if ($isWpContent) {
                $result['post_id'] = $match['post_id'];
                $result['permalink'] = get_permalink($match['post_id']) ?: '';
            }
            $results[] = $result;
        }

        return ['results' => $results];
    }
}
