<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

use TAW\Core\Corpus\Storage;

/**
 * Read-only reader over an installed catechism corpus
 * (`catechism-{edition}.sqlite`, installed via `bin/taw catechism:install` —
 * see {@see \TAW\CLI\CatechismInstallCommand}). Filename resolved per
 * edition via {@see CatechismEditions}. Mirrors
 * {@see \TAW\Core\Corpus\Bible\BibleReader} in every structural respect —
 * same "narrow, purpose-built, plain PDO, no WordPress dependency beyond
 * {@see \TAW\Core\Corpus\Storage}" posture — one level deeper in hierarchy
 * (part → section → chapter → numbered question/answer, vs. Scripture's
 * book → chapter → verse).
 *
 * Schema surface read: `parts`, `sections`, `chapters`, `paragraphs`,
 * `paragraphs_fts`. A source export can legitimately carry structure with
 * no content under it yet (confirmed in practice: an early Pius X export
 * briefly carried four empty, unrelated Trent-catechism part/section/
 * chapter stubs alongside the real content, before the upstream exporter's
 * own edition-scoping bug was fixed) — `parts()` prunes any *section* (and
 * transitively, part) left with zero paragraphs across every one of its
 * chapters, so a stray empty branch in a future export can never surface
 * as a dead-end in the UI. A single zero-paragraph *chapter* inside an
 * otherwise real section is kept, though, and deliberately not filtered:
 * the source book itself sometimes prints a chapter heading (e.g.
 * "CAPÍTULO II | DEL PRIMER ARTÍCULO DEL SÍMBOLO") that carries no
 * paragraphs of its own, immediately followed by several numbered
 * sub-items ("1º.- De Dios Padre...") the export stores as further sibling
 * chapter rows with no parent-child column between them — the theme's
 * reader regroups those client-side into a nested chapter/subchapter tree,
 * and needs this zero-paragraph heading row present in `parts()`'s output
 * to attach them to. `chapter()` still returns null for a zero-paragraph
 * id directly, since there's genuinely nothing to read there — the theme
 * renders such a row as a non-clickable group label rather than ever
 * calling `chapter()` on it.
 *
 * Deliberately not `final`, same reasoning as `BibleReader`: a theme can
 * swap in a differently-configured reader via
 * `apply_filters('taw_corpus_catechism_reader', ...)` — see
 * {@see \TAW\Core\Rest\CatechismEndpoint::reader()}.
 *
 * @phpstan-import-type Part from CatechismReaderInterface
 * @phpstan-import-type ChapterView from CatechismReaderInterface
 * @phpstan-import-type ParagraphSearchResult from CatechismReaderInterface
 */
class CatechismReader implements CatechismReaderInterface
{
    /** @var array<string, \PDO> */
    private array $pdo = [];

    public static function isInstalled(string $edition): bool
    {
        $filename = CatechismEditions::filename($edition);

        return $filename !== null && is_file(Storage::dbPath($filename));
    }

    protected function pdo(string $edition): \PDO
    {
        if (!isset($this->pdo[$edition])) {
            $filename = CatechismEditions::filename($edition);
            if ($filename === null) {
                throw new \InvalidArgumentException("Unknown catechism edition '{$edition}'.");
            }

            $this->pdo[$edition] = Storage::openReadOnly(Storage::dbPath($filename));
        }

        return $this->pdo[$edition];
    }

    /**
     * @return list<Part>
     */
    public function parts(string $edition): array
    {
        if (!self::isInstalled($edition)) {
            return [];
        }

        $rows = $this->pdo($edition)->query(
            'SELECT p.id AS part_id, p.name AS part_name, p."order" AS part_order,
                    s.id AS section_id, s.title AS section_title, s."order" AS section_order,
                    c.id AS chapter_id, c.title AS chapter_title, c."order" AS chapter_order,
                    COUNT(pg.id) AS paragraph_count
             FROM parts p
             JOIN sections s ON s.part_id = p.id
             JOIN chapters c ON c.section_id = s.id
             LEFT JOIN paragraphs pg ON pg.chapter_id = c.id
             GROUP BY c.id
             ORDER BY p."order", s."order", c."order"'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return self::buildTree($rows);
    }

    /**
     * @return ChapterView|null
     */
    public function chapter(string $edition, int $chapterId): ?array
    {
        if (!self::isInstalled($edition)) {
            return null;
        }

        $stmt = $this->pdo($edition)->prepare(
            'SELECT c.id AS chapter_id, c.title AS chapter_title,
                    s.id AS section_id, s.title AS section_title,
                    p.id AS part_id, p.name AS part_name
             FROM chapters c
             JOIN sections s ON s.id = c.section_id
             JOIN parts p ON p.id = s.part_id
             WHERE c.id = :chapter_id'
        );
        $stmt->execute(['chapter_id' => $chapterId]);
        $head = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($head === false) {
            return null;
        }

        $paragraphs = $this->pdo($edition)->prepare(
            'SELECT id, paragraph_number, question_text, answer_text
             FROM paragraphs
             WHERE chapter_id = :chapter_id
             ORDER BY paragraph_number'
        );
        $paragraphs->execute(['chapter_id' => $chapterId]);
        $paragraphRows = $paragraphs->fetchAll(\PDO::FETCH_ASSOC);

        if ($paragraphRows === []) {
            // A chapter with no paragraphs isn't a real reading unit — see
            // this class's own docblock on filtering empty structure.
            return null;
        }

        return [
            'part' => ['id' => (int) $head['part_id'], 'name' => (string) $head['part_name']],
            'section' => ['id' => (int) $head['section_id'], 'title' => (string) $head['section_title']],
            'chapter' => ['id' => (int) $head['chapter_id'], 'title' => (string) $head['chapter_title']],
            'paragraphs' => array_map(self::normalizeParagraphRow(...), $paragraphRows),
        ];
    }

    /**
     * FTS5 search over both `question_text` and `answer_text`. Same
     * per-word-AND-as-quoted-phrases escaping as
     * {@see \TAW\Core\Corpus\Bible\BibleReader::searchVerses()} — a query
     * must contain every word, in any order, not the query as one exact
     * contiguous phrase.
     *
     * @return list<ParagraphSearchResult>
     */
    public function searchParagraphs(string $edition, string $query, int $limit = 20): array
    {
        if (!self::isInstalled($edition) || trim($query) === '') {
            return [];
        }

        $stmt = $this->pdo($edition)->prepare(
            "SELECT pg.id, pg.paragraph_number, pg.question_text,
                    snippet(paragraphs_fts, -1, '<mark>', '</mark>', '…', 12) AS excerpt,
                    c.id AS chapter_id, c.title AS chapter_title,
                    s.title AS section_title, p.name AS part_name
             FROM paragraphs_fts
             JOIN paragraphs pg ON pg.id = paragraphs_fts.rowid
             JOIN chapters c ON c.id = pg.chapter_id
             JOIN sections s ON s.id = pg.section_id
             JOIN parts p ON p.id = pg.part_id
             WHERE paragraphs_fts MATCH :query
             ORDER BY rank
             LIMIT :limit"
        );
        $stmt->bindValue('query', self::escapeFtsPhrase($query), \PDO::PARAM_STR);
        $stmt->bindValue('limit', self::clampLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'paragraph_number' => (int) $r['paragraph_number'],
                'question_text' => $r['question_text'] !== null ? (string) $r['question_text'] : null,
                'excerpt' => (string) $r['excerpt'],
                'chapter_id' => (int) $r['chapter_id'],
                'chapter_title' => (string) $r['chapter_title'],
                'section_title' => (string) $r['section_title'],
                'part_name' => (string) $r['part_name'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * Folds the flat part/section/chapter join rows `parts()` selects into
     * the nested tree {@see CatechismReaderInterface::parts()} promises —
     * same grouping approach as
     * {@see \TAW\Core\Corpus\Bible\BibleReader::books()}'s
     * testament/division fold, one level deeper. Prunes any section whose
     * chapters carry zero paragraphs between them (and any part left with
     * no sections as a result) — see this class's own docblock for why
     * that's a section-level check, not a per-chapter one.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Part>
     */
    protected static function buildTree(array $rows): array
    {
        $parts = [];
        foreach ($rows as $row) {
            $partId = (int) $row['part_id'];
            $sectionId = (int) $row['section_id'];

            if (!isset($parts[$partId])) {
                $parts[$partId] = [
                    'id' => $partId,
                    'name' => (string) $row['part_name'],
                    'order' => (int) $row['part_order'],
                    'sections' => [],
                ];
            }
            if (!isset($parts[$partId]['sections'][$sectionId])) {
                $parts[$partId]['sections'][$sectionId] = [
                    'id' => $sectionId,
                    'title' => (string) $row['section_title'],
                    'order' => (int) $row['section_order'],
                    'chapters' => [],
                ];
            }

            $parts[$partId]['sections'][$sectionId]['chapters'][] = [
                'id' => (int) $row['chapter_id'],
                'title' => (string) $row['chapter_title'],
                'order' => (int) $row['chapter_order'],
                'paragraph_count' => (int) $row['paragraph_count'],
            ];
        }

        $tree = [];
        foreach ($parts as $part) {
            $sections = array_values(array_filter(
                $part['sections'],
                static fn (array $section): bool => array_sum(array_column($section['chapters'], 'paragraph_count')) > 0
            ));

            if ($sections === []) {
                continue;
            }

            $tree[] = [
                'id' => $part['id'],
                'name' => $part['name'],
                'order' => $part['order'],
                'sections' => $sections,
            ];
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, paragraph_number: int, question_text: ?string, answer_text: string}
     */
    private static function normalizeParagraphRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'paragraph_number' => (int) $row['paragraph_number'],
            'question_text' => $row['question_text'] !== null ? (string) $row['question_text'] : null,
            'answer_text' => (string) $row['answer_text'],
        ];
    }

    protected static function escapeFtsPhrase(string $query): string
    {
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false || $terms === []) {
            return '""';
        }

        return implode(' ', array_map(
            static fn (string $term): string => '"' . str_replace('"', '""', $term) . '"',
            $terms
        ));
    }

    protected static function clampLimit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
