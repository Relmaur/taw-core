<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Ingestion;

use TAW\Core\Log\Logger;
use TAW\Core\Rag\Chunking\TextChunker;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Vector\VectorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunk -> embed -> upsert for a single post, against `taw_vectors.sqlite`.
 * Invoked by {@see PostIndexer} (via WP-Cron) or `content:reindex`.
 */
final class IngestionPipeline
{
    private TextChunker $chunker;

    public function __construct(private readonly LlmClientInterface $llm, ?TextChunker $chunker = null)
    {
        $this->chunker = $chunker ?? new TextChunker();
    }

    public function ingestPost(int $postId): void
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $content = trim(wp_strip_all_tags((string) $post->post_content));
        if ($content === '') {
            $this->removePost($postId);
            return;
        }

        $chunks = $this->chunker->chunk($content, RagSettings::chunkSize(), RagSettings::chunkOverlap());
        if ($chunks === []) {
            $this->removePost($postId);
            return;
        }

        $model = RagSettings::embeddingModel();

        try {
            $embeddings = $this->llm->embeddings($chunks, $model);
        } catch (\Throwable $e) {
            Logger::warning('rag.ingestion_failed', 'Failed to embed post content.', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->repository()->upsertChunks($postId, $chunks, $embeddings, $model);

        Logger::info('rag.post_indexed', 'Post indexed for RAG search.', [
            'post_id' => $postId,
            'chunks' => count($chunks),
        ]);
    }

    public function removePost(int $postId): void
    {
        $this->repository()->deletePost($postId);
    }

    private function repository(): VectorRepository
    {
        $dir = Storage::dir();
        Storage::ensureProtectedDir($dir);

        return new VectorRepository(Storage::openSqlite(Storage::dbPath('taw_vectors.sqlite')));
    }
}
