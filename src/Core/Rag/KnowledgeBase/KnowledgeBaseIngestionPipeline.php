<?php

declare(strict_types=1);

namespace TAW\Core\Rag\KnowledgeBase;

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
 * Reads an admin-uploaded .sqlite knowledge base, embeds its content, and
 * writes the vector index directly into the same file (a `taw_rag_chunks`
 * table alongside the file's own arbitrary schema — one file per knowledge
 * base, not two). Hooked to WP-Cron ({@see \TAW\Core\Rag\Ingestion\PostIndexer}'s
 * dispatch reasoning applies here too: an embeddings API call is external
 * network I/O, so the upload request that queues this never waits on it).
 */
final class KnowledgeBaseIngestionPipeline
{
    private TextChunker $chunker;
    private GenericSqliteIngestor $ingestor;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly KnowledgeBaseRegistry $registry = new KnowledgeBaseRegistry(),
        ?TextChunker $chunker = null,
        ?GenericSqliteIngestor $ingestor = null,
    ) {
        $this->chunker = $chunker ?? new TextChunker();
        $this->ingestor = $ingestor ?? new GenericSqliteIngestor();
    }

    /**
     * @return array{id: string, name: string, description: string, source_file: ?string, status: string, chunk_count: int, created_at: string}|null
     *         The updated record on success, null on any failure (including
     *         an unknown id).
     */
    public function ingest(string $id): ?array
    {
        $kb = $this->registry->find($id);
        if ($kb === null || $kb['source_file'] === null) {
            return null;
        }

        $path = Storage::dbPath($kb['source_file']);
        if (!is_file($path)) {
            return $this->fail($id, 'Knowledge base source file is missing.');
        }

        try {
            $pdo = Storage::openSqlite($path);
            $rowTexts = $this->ingestor->extractRows($pdo);
        } catch (\Throwable $e) {
            return $this->fail($id, 'Failed to read the knowledge base source file.', $e->getMessage());
        }

        $chunks = [];
        foreach ($rowTexts as $rowText) {
            foreach ($this->chunker->chunk($rowText, RagSettings::chunkSize(), RagSettings::chunkOverlap()) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        if ($chunks === []) {
            return $this->fail($id, 'No text content found in the uploaded file.');
        }

        $model = RagSettings::embeddingModel();

        try {
            $embeddings = $this->llm->embeddings($chunks, $model);
        } catch (\Throwable $e) {
            return $this->fail($id, 'Failed to embed knowledge base content.', $e->getMessage());
        }

        $rows = [];
        foreach ($chunks as $index => $content) {
            $rows[] = ['content' => $content, 'embedding' => $embeddings[$index]];
        }

        (new VectorRepository($pdo))->replaceAll($rows, $model);
        $updated = $this->registry->updateStatus($id, 'ready', count($rows));

        Logger::info('rag.kb_ingested', 'Knowledge base ingested.', ['id' => $id, 'chunks' => count($rows)]);

        return $updated;
    }

    /**
     * Always returns null — lets call sites `return $this->fail(...);` directly.
     */
    private function fail(string $id, string $message, ?string $error = null): null
    {
        $this->registry->updateStatus($id, 'failed');

        $context = ['id' => $id];
        if ($error !== null) {
            $context['error'] = $error;
        }

        Logger::warning('rag.kb_ingestion_failed', $message, $context);

        return null;
    }
}
