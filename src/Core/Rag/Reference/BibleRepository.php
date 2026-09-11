<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

/**
 * Read-only query wrapper over the `verses` table (see
 * {@see ReferenceSchema::bible()}). No ABSPATH guard — pure PDO.
 */
final class BibleRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
        SchemaManager::ensureTable($this->pdo, ReferenceSchema::bible());
    }

    /**
     * @return list<array{book: string, chapter: string, verse: string, text: string}>
     */
    public function lookup(string $book, string $chapter, ?string $verse = null): array
    {
        $sql = 'SELECT book, chapter, verse, text FROM verses WHERE book = :book AND chapter = :chapter';
        $params = ['book' => $book, 'chapter' => $chapter];

        if ($verse !== null && $verse !== '') {
            $sql .= ' AND verse = :verse';
            $params['verse'] = $verse;
        }

        $sql .= ' ORDER BY CAST(verse AS INTEGER)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{book: string, chapter: string, verse: string, text: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }
}
