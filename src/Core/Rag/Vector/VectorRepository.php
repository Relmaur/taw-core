<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Vector;

/**
 * No ABSPATH guard — takes an explicit PDO, no `wp_upload_dir()` or other
 * WordPress call inside, which is what keeps it unit-testable against a
 * temp SQLite file.
 */
final class VectorRepository
{
    /**
     * Full-table-scan ceiling for the pure-PHP fallback search — fine at
     * current TAW site sizes, a real limit worth revisiting once any site's
     * archive grows large before sqlite-vec becomes available anywhere.
     */
    private const FALLBACK_SCAN_LIMIT = 5000;

    public function __construct(private readonly \PDO $pdo)
    {
        SchemaManager::ensureVectorSchema($this->pdo);
    }

    /**
     * @param list<string> $chunks
     * @param list<list<float>> $embeddings
     */
    public function upsertChunks(int $postId, array $chunks, array $embeddings, string $model): void
    {
        if (count($chunks) !== count($embeddings)) {
            throw new \InvalidArgumentException('$chunks and $embeddings must have the same length.');
        }

        // Chunk boundaries can shift between re-indexes (edited content),
        // so a full delete+reinsert per post is the only reliably-correct
        // strategy — there is no stable per-chunk identity to diff against.
        $this->deletePost($postId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO chunks (post_id, chunk_index, content, embedding, model, updated_at)
             VALUES (:post_id, :chunk_index, :content, :embedding, :model, :updated_at)'
        );

        foreach ($chunks as $index => $content) {
            $stmt->execute([
                'post_id' => $postId,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => VectorMath::packVector($embeddings[$index]),
                'model' => $model,
                'updated_at' => gmdate('c'),
            ]);
        }
    }

    public function deletePost(int $postId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM chunks WHERE post_id = :post_id');
        $stmt->execute(['post_id' => $postId]);
    }

    /**
     * @param list<float> $queryVector
     * @return list<array{post_id: int, chunk_index: int, content: string, score: float}>
     */
    public function search(array $queryVector, int $limit = 5): array
    {
        if (VectorCapability::sqliteVecAvailable($this->pdo)) {
            try {
                return $this->searchWithSqliteVec($queryVector, $limit);
            } catch (\Throwable) {
                // Never let an accelerator failure take search down —
                // fall through to the always-correct pure-PHP path.
            }
        }

        return $this->searchWithBruteForce($queryVector, $limit);
    }

    /**
     * @param list<float> $queryVector
     * @return list<array{post_id: int, chunk_index: int, content: string, score: float}>
     */
    private function searchWithBruteForce(array $queryVector, int $limit): array
    {
        $stmt = $this->pdo->query(sprintf(
            'SELECT post_id, chunk_index, content, embedding FROM chunks LIMIT %d',
            self::FALLBACK_SCAN_LIMIT
        ));

        $scored = [];
        foreach ($stmt as $row) {
            $scored[] = [
                'post_id' => (int) $row['post_id'],
                'chunk_index' => (int) $row['chunk_index'],
                'content' => (string) $row['content'],
                'score' => VectorMath::cosineSimilarity($queryVector, VectorMath::unpackVector((string) $row['embedding'])),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Accelerator path — sqlite-vec is confirmed absent on every target
     * host today, so this is exercised only once the extension is actually
     * installed somewhere. Any failure here is caught by search() and
     * falls back to searchWithBruteForce().
     *
     * @param list<float> $queryVector
     * @return list<array{post_id: int, chunk_index: int, content: string, score: float}>
     */
    private function searchWithSqliteVec(array $queryVector, int $limit): array
    {
        $this->ensureVecTable(count($queryVector));

        $stmt = $this->pdo->prepare(
            'SELECT c.post_id, c.chunk_index, c.content, v.distance AS distance
             FROM vec_chunks v
             JOIN chunks c ON c.id = v.rowid
             WHERE v.embedding MATCH :query AND k = :limit
             ORDER BY v.distance'
        );
        $stmt->bindValue(':query', VectorMath::packVector($queryVector), \PDO::PARAM_LOB);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt as $row) {
            $results[] = [
                'post_id' => (int) $row['post_id'],
                'chunk_index' => (int) $row['chunk_index'],
                'content' => (string) $row['content'],
                'score' => 1.0 - (float) $row['distance'],
            ];
        }

        return $results;
    }

    private function ensureVecTable(int $dimension): void
    {
        $this->pdo->exec(sprintf(
            'CREATE VIRTUAL TABLE IF NOT EXISTS vec_chunks USING vec0(embedding float[%d])',
            $dimension
        ));

        $existing = (int) ($this->pdo->query('SELECT COUNT(*) FROM vec_chunks')->fetchColumn() ?: 0);
        $expected = (int) ($this->pdo->query('SELECT COUNT(*) FROM chunks')->fetchColumn() ?: 0);

        if ($existing < $expected) {
            $this->pdo->exec('DELETE FROM vec_chunks');
            $this->pdo->exec('INSERT INTO vec_chunks (rowid, embedding) SELECT id, embedding FROM chunks');
        }
    }
}
