<?php

declare(strict_types=1);

namespace TAW\Core\Rag\KnowledgeBase;

use TAW\Core\Rag\RagSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD over the admin-uploaded knowledge base registry (`_taw_rag_knowledge_bases`
 * option). The site's own WP content is a synthetic, always-present,
 * non-deletable entry ({@see self::WP_CONTENT_ID}) derived from
 * {@see RagSettings} rather than stored here — nothing new to keep in sync
 * with the ingestion pipeline that already governs it.
 *
 * @phpstan-type KnowledgeBase array{id: string, name: string, description: string, source_file: ?string, status: string, chunk_count: int, created_at: string}
 */
final class KnowledgeBaseRegistry
{
    public const WP_CONTENT_ID = 'wp-content';

    private const OPTION = '_taw_rag_knowledge_bases';

    /**
     * @return list<KnowledgeBase>
     */
    public function all(): array
    {
        $knowledgeBases = [$this->wpContentEntry()];

        foreach ($this->stored() as $kb) {
            $knowledgeBases[] = $this->normalize($kb);
        }

        return $knowledgeBases;
    }

    /**
     * @return KnowledgeBase|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $kb) {
            if ($kb['id'] === $id) {
                return $kb;
            }
        }

        return null;
    }

    /**
     * @return KnowledgeBase
     */
    public function add(string $id, string $name, string $description, string $sourceFile): array
    {
        $kb = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'source_file' => $sourceFile,
            'status' => 'pending',
            'chunk_count' => 0,
            'created_at' => gmdate('c'),
        ];

        $stored = $this->stored();
        $stored[] = $kb;
        update_option(self::OPTION, $stored);

        return $kb;
    }

    /**
     * @return KnowledgeBase|null The updated record, or null if $id isn't a stored (uploaded) knowledge base.
     */
    public function updateStatus(string $id, string $status, int $chunkCount = 0): ?array
    {
        $stored = $this->stored();
        $updated = null;

        foreach ($stored as $index => $kb) {
            if ($kb['id'] === $id) {
                $stored[$index]['status'] = $status;
                $stored[$index]['chunk_count'] = $chunkCount;
                $updated = $stored[$index];
            }
        }

        update_option(self::OPTION, $stored);

        return $updated;
    }

    public function delete(string $id): void
    {
        $stored = array_values(array_filter(
            $this->stored(),
            static fn (array $kb): bool => $kb['id'] !== $id
        ));
        update_option(self::OPTION, $stored);
    }

    public static function generateId(): string
    {
        return 'kb-' . bin2hex(random_bytes(4));
    }

    /**
     * @return list<KnowledgeBase>
     */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($stored) as $kb) {
            if (is_array($kb) && isset($kb['id'])) {
                $normalized[] = $this->normalize($kb);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $kb
     * @return KnowledgeBase
     */
    private function normalize(array $kb): array
    {
        return [
            'id' => (string) $kb['id'],
            'name' => (string) ($kb['name'] ?? ''),
            'description' => (string) ($kb['description'] ?? ''),
            'source_file' => isset($kb['source_file']) ? (string) $kb['source_file'] : null,
            'status' => (string) ($kb['status'] ?? 'pending'),
            'chunk_count' => (int) ($kb['chunk_count'] ?? 0),
            'created_at' => (string) ($kb['created_at'] ?? ''),
        ];
    }

    /**
     * @return KnowledgeBase
     */
    private function wpContentEntry(): array
    {
        $types = implode(', ', RagSettings::indexedPostTypes());

        return [
            'id' => self::WP_CONTENT_ID,
            'name' => "This site's content",
            'description' => "This website's own content ({$types}).",
            'source_file' => null,
            'status' => 'ready',
            'chunk_count' => 0,
            'created_at' => '',
        ];
    }
}
