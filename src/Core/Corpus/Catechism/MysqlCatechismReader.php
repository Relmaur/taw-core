<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

/**
 * MySQL-backed mirror of {@see CatechismReader}, selected transparently by
 * {@see \TAW\Core\Rest\CatechismEndpoint} when `pdo_sqlite` isn't available
 * on the host — same reasoning and same independent-implementation
 * decision as {@see \TAW\Core\Corpus\Bible\MysqlBibleReader} (read that
 * class's docblock for the "why a separate implementation, not a shared
 * base" rationale; it applies here unchanged).
 *
 * Every query filters on `edition` alongside whatever `source_id`-based
 * join is otherwise needed — see {@see MysqlCatechismSchema}'s own
 * docblock for why the source ids alone aren't unique across editions.
 *
 * Reuses the exact `innodb_ft_min_token_size` short-word-filtering
 * approach {@see \TAW\Core\Corpus\Bible\MysqlBibleReader} needed after a
 * real production incident (short Spanish words silently dropped from
 * InnoDB's FULLTEXT index made any query containing one return zero
 * results) — applied here from the start rather than rediscovered.
 *
 * @phpstan-import-type Part from CatechismReaderInterface
 * @phpstan-import-type ChapterView from CatechismReaderInterface
 * @phpstan-import-type ParagraphSearchResult from CatechismReaderInterface
 */
class MysqlCatechismReader implements CatechismReaderInterface
{
    private const EXCERPT_RADIUS = 80;

    public static function isInstalled(string $edition): bool
    {
        global $wpdb;

        $table = MysqlCatechismSchema::parts();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return false;
        }

        return ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition = %s",
            $edition
        ))) > 0;
    }

    public function parts(string $edition): array
    {
        global $wpdb;

        if (!self::isInstalled($edition)) {
            return [];
        }

        $parts = MysqlCatechismSchema::parts();
        $sections = MysqlCatechismSchema::sections();
        $chapters = MysqlCatechismSchema::chapters();
        $paragraphs = MysqlCatechismSchema::paragraphs();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.source_id AS part_id, p.name AS part_name, p.part_order,
                    s.source_id AS section_id, s.title AS section_title, s.section_order,
                    c.source_id AS chapter_id, c.title AS chapter_title, c.chapter_order,
                    COUNT(pg.id) AS paragraph_count
             FROM {$parts} p
             JOIN {$sections} s ON s.edition = p.edition AND s.part_id = p.source_id
             JOIN {$chapters} c ON c.edition = s.edition AND c.section_id = s.source_id
             LEFT JOIN {$paragraphs} pg ON pg.edition = c.edition AND pg.chapter_id = c.source_id
             WHERE p.edition = %s
             GROUP BY c.id
             ORDER BY p.part_order, s.section_order, c.chapter_order",
            $edition
        ), ARRAY_A);

        return self::buildTree((array) $rows);
    }

    public function chapter(string $edition, int $chapterId): ?array
    {
        global $wpdb;

        if (!self::isInstalled($edition)) {
            return null;
        }

        $parts = MysqlCatechismSchema::parts();
        $sections = MysqlCatechismSchema::sections();
        $chapters = MysqlCatechismSchema::chapters();
        $paragraphs = MysqlCatechismSchema::paragraphs();

        $head = $wpdb->get_row($wpdb->prepare(
            "SELECT c.source_id AS chapter_id, c.title AS chapter_title,
                    s.source_id AS section_id, s.title AS section_title,
                    p.source_id AS part_id, p.name AS part_name
             FROM {$chapters} c
             JOIN {$sections} s ON s.edition = c.edition AND s.source_id = c.section_id
             JOIN {$parts} p ON p.edition = s.edition AND p.source_id = s.part_id
             WHERE c.edition = %s AND c.source_id = %d",
            $edition,
            $chapterId
        ), ARRAY_A);

        if ($head === null) {
            return null;
        }

        $paragraphRows = $wpdb->get_results($wpdb->prepare(
            "SELECT source_id AS id, paragraph_number, question_text, answer_text
             FROM {$paragraphs}
             WHERE edition = %s AND chapter_id = %d
             ORDER BY paragraph_number",
            $edition,
            $chapterId
        ), ARRAY_A);

        if ($paragraphRows === null || $paragraphRows === []) {
            return null;
        }

        return [
            'part' => ['id' => (int) $head['part_id'], 'name' => (string) $head['part_name']],
            'section' => ['id' => (int) $head['section_id'], 'title' => (string) $head['section_title']],
            'chapter' => ['id' => (int) $head['chapter_id'], 'title' => (string) $head['chapter_title']],
            'paragraphs' => array_map(
                static fn (array $r): array => [
                    'id' => (int) $r['id'],
                    'paragraph_number' => (int) $r['paragraph_number'],
                    'question_text' => $r['question_text'] !== null ? (string) $r['question_text'] : null,
                    'answer_text' => (string) $r['answer_text'],
                ],
                (array) $paragraphRows
            ),
        ];
    }

    public function searchParagraphs(string $edition, string $query, int $limit = 20): array
    {
        global $wpdb;

        if (!self::isInstalled($edition)) {
            return [];
        }

        $words = self::queryWords($query);
        if ($words === []) {
            return [];
        }

        $significant = self::significantWords($words);
        if ($significant === []) {
            return [];
        }

        $paragraphs = MysqlCatechismSchema::paragraphs();
        $chapters = MysqlCatechismSchema::chapters();
        $sections = MysqlCatechismSchema::sections();
        $parts = MysqlCatechismSchema::parts();
        $boolean = self::booleanModeQuery($significant);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pg.source_id AS id, pg.paragraph_number, pg.question_text, pg.answer_text,
                    c.source_id AS chapter_id, c.title AS chapter_title,
                    s.title AS section_title, p.name AS part_name
             FROM {$paragraphs} pg
             JOIN {$chapters} c ON c.edition = pg.edition AND c.source_id = pg.chapter_id
             JOIN {$sections} s ON s.edition = pg.edition AND s.source_id = pg.section_id
             JOIN {$parts} p ON p.edition = pg.edition AND p.source_id = pg.part_id
             WHERE pg.edition = %s
               AND MATCH(pg.question_text, pg.answer_text) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY MATCH(pg.question_text, pg.answer_text) AGAINST (%s IN BOOLEAN MODE) DESC
             LIMIT %d",
            $edition,
            $boolean,
            $boolean,
            self::clampLimit($limit)
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'paragraph_number' => (int) $r['paragraph_number'],
                'question_text' => $r['question_text'] !== null ? (string) $r['question_text'] : null,
                'excerpt' => self::buildExcerpt(
                    trim(((string) ($r['question_text'] ?? '')) . ' ' . (string) $r['answer_text']),
                    $words
                ),
                'chapter_id' => (int) $r['chapter_id'],
                'chapter_title' => (string) $r['chapter_title'],
                'section_title' => (string) $r['section_title'],
                'part_name' => (string) $r['part_name'],
            ],
            (array) $rows
        );
    }

    /* -----------------------------------------------------------------
     * Internals — identical approach to MysqlBibleReader's, see that
     * class's docblocks for the rationale behind each of these.
     * ----------------------------------------------------------------- */

    /**
     * Folds the flat part/section/chapter join rows `parts()` selects into
     * the nested tree {@see CatechismReaderInterface::parts()} promises.
     * Deliberately duplicated from {@see CatechismReader::buildTree()}
     * rather than shared — same "two independent implementations, no
     * shared base" decision {@see \TAW\Core\Corpus\Bible\MysqlBibleReader}
     * already made for its own row-folding logic. Prunes any section whose
     * chapters carry zero paragraphs between them (and any part left with
     * no sections as a result) — see {@see CatechismReader}'s docblock for
     * why that's a section-level check, not a per-chapter one: a chapter
     * heading with zero paragraphs of its own but real sub-item chapters
     * following it must survive so the theme can regroup them client-side.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Part>
     */
    private static function buildTree(array $rows): array
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
     * @return list<string>
     */
    private static function queryWords(string $query): array
    {
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false) {
            return [];
        }

        $clean = [];
        foreach ($terms as $term) {
            $stripped = preg_replace('/[+\-<>()~*"@]+/', '', $term) ?? '';
            if ($stripped !== '') {
                $clean[] = $stripped;
            }
        }

        return $clean;
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    private static function significantWords(array $words): array
    {
        $minLength = self::innodbFtMinTokenSize();

        return array_values(array_filter(
            $words,
            static fn (string $w): bool => mb_strlen($w) >= $minLength
        ));
    }

    private static function innodbFtMinTokenSize(): int
    {
        global $wpdb;

        $value = $wpdb->get_var('SELECT @@innodb_ft_min_token_size');

        return $value !== null ? (int) $value : 3;
    }

    /**
     * @param list<string> $words
     */
    private static function booleanModeQuery(array $words): string
    {
        return implode(' ', array_map(static fn (string $w): string => '+' . $w, $words));
    }

    /**
     * @param list<string> $words
     */
    private static function buildExcerpt(string $text, array $words): string
    {
        $earliest = null;
        foreach ($words as $word) {
            $pos = mb_stripos($text, $word);
            if ($pos !== false && ($earliest === null || $pos < $earliest)) {
                $earliest = $pos;
            }
        }

        $start = max(0, ($earliest ?? 0) - self::EXCERPT_RADIUS);
        $length = self::EXCERPT_RADIUS * 2;
        $snippet = mb_substr($text, $start, $length);

        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($text) ? '…' : '';

        $pattern = implode('|', array_map(
            static fn (string $w): string => preg_quote($w, '/'),
            $words
        ));
        $highlighted = $pattern === ''
            ? $snippet
            : (preg_replace('/(' . $pattern . ')/iu', '<mark>$1</mark>', $snippet) ?? $snippet);

        return $prefix . $highlighted . $suffix;
    }

    private static function clampLimit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
