<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Bible;

/**
 * MySQL-backed mirror of {@see BibleReader}, selected transparently by
 * {@see \TAW\Core\Rest\BibleEndpoint} when `pdo_sqlite` isn't available on
 * the host (`TAW\Core\Storage\ProtectedSqlite::isAvailable()` returns
 * false) — confirmed to happen on real managed hosting (WPMUdev declines
 * to add the extension; `$wpdb` — and the `mysqli` extension it's built
 * on — is guaranteed on every WordPress host, since WP core itself can't
 * function without it, unlike `pdo_sqlite`, which nothing requires).
 *
 * Deliberately a separate, independent implementation rather than sharing
 * internals with {@see BibleReader} via an abstract base — the two
 * backends' query/escaping logic differs enough (SQLite FTS5 vs MySQL
 * boolean-mode FULLTEXT, no `snippet()` equivalent in MySQL) that forcing
 * a shared base would mostly move complexity around rather than remove
 * it, and it keeps {@see BibleReader} — already shipped, already tested —
 * completely untouched by this addition. Same output shape either way,
 * enforced by both implementing {@see BibleReaderInterface}.
 *
 * Uses `global $wpdb` directly (standard WordPress convention) rather
 * than a constructor-injected dependency — matches how every other
 * `$wpdb`-touching class in a WordPress codebase is written, and keeps
 * this a plain `new MysqlBibleReader()` the same way `BibleReader` is.
 */
class MysqlBibleReader implements BibleReaderInterface
{
    private const EXCERPT_RADIUS = 80;

    public static function isInstalled(): bool
    {
        global $wpdb;

        $table = MysqlBibleSchema::books();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return false;
        }

        return ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}")) > 0;
    }

    public function books(): array
    {
        global $wpdb;

        $books = MysqlBibleSchema::books();
        $chapters = MysqlBibleSchema::chapters();

        $rows = $wpdb->get_results(
            "SELECT b.id, b.slug, b.name, b.full_name, b.latin_name, b.abbreviation, b.canon,
                    b.testament, b.division, b.book_order, COUNT(c.id) AS chapter_count
             FROM {$books} b
             LEFT JOIN {$chapters} c ON c.book_id = b.id
             GROUP BY b.id
             ORDER BY b.book_order",
            ARRAY_A
        );

        $testaments = [];
        foreach ((array) $rows as $row) {
            $testamentName = (string) $row['testament'];
            $divisionName = $row['division'] !== null ? (string) $row['division'] : null;
            $divisionKey = $divisionName ?? "\0none";

            if (!isset($testaments[$testamentName])) {
                $testaments[$testamentName] = ['testament' => $testamentName, 'divisions' => []];
            }
            if (!isset($testaments[$testamentName]['divisions'][$divisionKey])) {
                $testaments[$testamentName]['divisions'][$divisionKey] = ['division' => $divisionName, 'books' => []];
            }
            $testaments[$testamentName]['divisions'][$divisionKey]['books'][] = $this->normalizeBookRow($row);
        }

        return array_values(array_map(
            static fn (array $t): array => ['testament' => $t['testament'], 'divisions' => array_values($t['divisions'])],
            $testaments
        ));
    }

    public function chapter(string $bookSlug, int $chapterNumber): ?array
    {
        global $wpdb;

        $book = $this->fetchBookBySlug($bookSlug);
        if ($book === null) {
            return null;
        }

        $chapters = MysqlBibleSchema::chapters();
        $chapterId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$chapters} WHERE book_id = %d AND chapter_number = %d",
            $book['id'],
            $chapterNumber
        ));
        if ($chapterId === null) {
            return null;
        }

        return [
            'book' => $book,
            'chapter_number' => $chapterNumber,
            'verses' => $this->fetchVerses((int) $book['id'], (int) $chapterId),
            'sections' => $this->fetchSections((int) $book['id'], $chapterNumber),
            'notes' => $this->fetchNotes((int) $book['id'], $chapterNumber),
        ];
    }

    public function searchVerses(string $query, int $limit = 20): array
    {
        global $wpdb;

        $words = self::queryWords($query);
        if ($words === []) {
            return [];
        }

        $verses = MysqlBibleSchema::verses();
        $books = MysqlBibleSchema::books();
        $chapters = MysqlBibleSchema::chapters();
        $boolean = self::booleanModeQuery($words);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.verse_number, v.verse_label, v.text,
                    b.slug AS book_slug, b.name AS book_name, b.abbreviation AS book_abbreviation,
                    c.chapter_number
             FROM {$verses} v
             JOIN {$books} b ON b.id = v.book_id
             JOIN {$chapters} c ON c.id = v.chapter_id
             WHERE MATCH(v.text) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY MATCH(v.text) AGAINST (%s IN BOOLEAN MODE) DESC
             LIMIT %d",
            $boolean,
            $boolean,
            self::clampLimit($limit)
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'verse_number' => (int) $r['verse_number'],
                'verse_label' => (string) $r['verse_label'],
                'text' => (string) $r['text'],
                'excerpt' => self::buildExcerpt((string) $r['text'], $words),
                'book_slug' => (string) $r['book_slug'],
                'book_name' => (string) $r['book_name'],
                'book_abbreviation' => (string) $r['book_abbreviation'],
                'chapter_number' => (int) $r['chapter_number'],
            ],
            (array) $rows
        );
    }

    public function searchNotes(string $query, int $limit = 20): array
    {
        global $wpdb;

        $words = self::queryWords($query);
        if ($words === []) {
            return [];
        }

        $notes = MysqlBibleSchema::notes();
        $books = MysqlBibleSchema::books();
        $boolean = self::booleanModeQuery($words);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.type, n.marker, n.start_chapter, n.start_verse, n.body,
                    b.slug AS book_slug, b.name AS book_name
             FROM {$notes} n
             JOIN {$books} b ON b.id = n.book_id
             WHERE MATCH(n.body) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY MATCH(n.body) AGAINST (%s IN BOOLEAN MODE) DESC
             LIMIT %d",
            $boolean,
            $boolean,
            self::clampLimit($limit)
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'type' => (string) $r['type'],
                'marker' => $r['marker'] !== null ? (string) $r['marker'] : null,
                'excerpt' => self::buildExcerpt((string) $r['body'], $words),
                'book_slug' => (string) $r['book_slug'],
                'book_name' => (string) $r['book_name'],
                'start_chapter' => $r['start_chapter'] !== null ? (int) $r['start_chapter'] : null,
                'start_verse' => $r['start_verse'] !== null ? (int) $r['start_verse'] : null,
            ],
            (array) $rows
        );
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * @return array{id: int, slug: string, name: string, full_name: ?string, latin_name: ?string, abbreviation: string, canon: string, testament: string, division: ?string, book_order: int, chapter_count: int}|null
     */
    private function fetchBookBySlug(string $slug): ?array
    {
        global $wpdb;

        $books = MysqlBibleSchema::books();
        $chapters = MysqlBibleSchema::chapters();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT b.id, b.slug, b.name, b.full_name, b.latin_name, b.abbreviation, b.canon,
                    b.testament, b.division, b.book_order, COUNT(c.id) AS chapter_count
             FROM {$books} b
             LEFT JOIN {$chapters} c ON c.book_id = b.id
             WHERE b.slug = %s
             GROUP BY b.id",
            $slug
        ), ARRAY_A);

        return $row === null ? null : $this->normalizeBookRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, slug: string, name: string, full_name: ?string, latin_name: ?string, abbreviation: string, canon: string, testament: string, division: ?string, book_order: int, chapter_count: int}
     */
    private function normalizeBookRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'full_name' => $row['full_name'] !== null ? (string) $row['full_name'] : null,
            'latin_name' => $row['latin_name'] !== null ? (string) $row['latin_name'] : null,
            'abbreviation' => (string) $row['abbreviation'],
            'canon' => (string) $row['canon'],
            'testament' => (string) $row['testament'],
            'division' => $row['division'] !== null ? (string) $row['division'] : null,
            'book_order' => (int) $row['book_order'],
            'chapter_count' => (int) $row['chapter_count'],
        ];
    }

    /**
     * @return list<array{id: int, verse_number: int, verse_label: string, text: string, is_editorial_addition: bool}>
     */
    private function fetchVerses(int $bookId, int $chapterId): array
    {
        global $wpdb;

        $verses = MysqlBibleSchema::verses();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, verse_number, verse_label, text, is_editorial_addition
             FROM {$verses}
             WHERE book_id = %d AND chapter_id = %d
             ORDER BY verse_number",
            $bookId,
            $chapterId
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'verse_number' => (int) $r['verse_number'],
                'verse_label' => (string) $r['verse_label'],
                'text' => (string) $r['text'],
                'is_editorial_addition' => ((int) $r['is_editorial_addition']) === 1,
            ],
            (array) $rows
        );
    }

    /**
     * @return list<array{id: int, parent_id: ?int, kind: string, heading: string, subheading: ?string, body: ?string, start_chapter: int, start_verse: ?int, end_chapter: int, end_verse: ?int, position: int}>
     */
    private function fetchSections(int $bookId, int $chapterNumber): array
    {
        global $wpdb;

        $sections = MysqlBibleSchema::sections();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, parent_id, kind, heading, subheading, body, start_chapter, start_verse, end_chapter, end_verse, position
             FROM {$sections}
             WHERE book_id = %d AND start_chapter <= %d AND end_chapter >= %d
             ORDER BY position",
            $bookId,
            $chapterNumber,
            $chapterNumber
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'kind' => (string) $r['kind'],
                'heading' => (string) $r['heading'],
                'subheading' => $r['subheading'] !== null ? (string) $r['subheading'] : null,
                'body' => $r['body'] !== null ? (string) $r['body'] : null,
                'start_chapter' => (int) $r['start_chapter'],
                'start_verse' => $r['start_verse'] !== null ? (int) $r['start_verse'] : null,
                'end_chapter' => (int) $r['end_chapter'],
                'end_verse' => $r['end_verse'] !== null ? (int) $r['end_verse'] : null,
                'position' => (int) $r['position'],
            ],
            (array) $rows
        );
    }

    /**
     * @return list<array{id: int, type: string, marker: ?string, body: string, start_chapter: ?int, start_verse: ?int, end_chapter: ?int, end_verse: ?int, position: int}>
     */
    private function fetchNotes(int $bookId, int $chapterNumber): array
    {
        global $wpdb;

        $notes = MysqlBibleSchema::notes();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, marker, body, start_chapter, start_verse, end_chapter, end_verse, position
             FROM {$notes}
             WHERE book_id = %d AND start_chapter <= %d AND end_chapter >= %d
             ORDER BY position",
            $bookId,
            $chapterNumber,
            $chapterNumber
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'type' => (string) $r['type'],
                'marker' => $r['marker'] !== null ? (string) $r['marker'] : null,
                'body' => (string) $r['body'],
                'start_chapter' => $r['start_chapter'] !== null ? (int) $r['start_chapter'] : null,
                'start_verse' => $r['start_verse'] !== null ? (int) $r['start_verse'] : null,
                'end_chapter' => $r['end_chapter'] !== null ? (int) $r['end_chapter'] : null,
                'end_verse' => $r['end_verse'] !== null ? (int) $r['end_verse'] : null,
                'position' => (int) $r['position'],
            ],
            (array) $rows
        );
    }

    /**
     * Splits a raw query into the individual words used both to build the
     * boolean-mode MATCH expression and to highlight the excerpt — the
     * same "AND every word, don't expose operator syntax" semantics
     * {@see BibleReader::searchVerses()} uses for SQLite FTS5, translated
     * to MySQL's boolean mode instead of quoted-phrase-per-word.
     *
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
            // Strip MySQL boolean-mode operators so user input is always
            // treated as a plain word, never as +/-/</>/(/)/~/*/"/@ syntax.
            $stripped = preg_replace('/[+\-<>()~*"@]+/', '', $term) ?? '';
            if ($stripped !== '') {
                $clean[] = $stripped;
            }
        }

        return $clean;
    }

    /**
     * @param list<string> $words
     */
    private static function booleanModeQuery(array $words): string
    {
        return implode(' ', array_map(static fn (string $w): string => '+' . $w, $words));
    }

    /**
     * MySQL's FULLTEXT has no `snippet()` equivalent — builds a
     * SQLite-FTS5-`snippet()`-shaped excerpt by hand: finds the earliest
     * match among the search words (case-insensitive), slices a window
     * around it, and wraps every matched word in `<mark>`.
     *
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
