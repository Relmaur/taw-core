<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

/**
 * Read-only query wrapper over the `entries` table (see
 * {@see ReferenceSchema::catechism()}). No ABSPATH guard — pure PDO.
 */
final class CatechismRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
        SchemaManager::ensureTable($this->pdo, ReferenceSchema::catechism());
    }

    /**
     * Accepts either an exact "part/question" or "part:question"
     * reference, or a free-text topic keyword to search against.
     *
     * @return list<array{part: string, question: string, topic: string, text: string}>
     */
    public function lookup(string $topicOrNumber): array
    {
        $topicOrNumber = trim($topicOrNumber);

        if (preg_match('/^(.+?)\s*[:\/]\s*(.+)$/', $topicOrNumber, $matches) === 1) {
            $stmt = $this->pdo->prepare(
                'SELECT part, question, topic, text FROM entries WHERE part = :part AND question = :question LIMIT 1'
            );
            $stmt->execute(['part' => $matches[1], 'question' => $matches[2]]);

            /** @var array{part: string, question: string, topic: string, text: string}|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                return [$row];
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT part, question, topic, text FROM entries WHERE topic LIKE :needle OR text LIKE :needle LIMIT 10'
        );
        $stmt->execute(['needle' => '%' . $topicOrNumber . '%']);

        /** @var list<array{part: string, question: string, topic: string, text: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }
}
