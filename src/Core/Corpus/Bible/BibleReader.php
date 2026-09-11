<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Bible;

use TAW\Core\Corpus\Storage;

/**
 * Read-only reader over an installed Straubinger-schema Bible corpus
 * (`bible-straubinger.sqlite`, installed via `bin/taw corpus:install` — see
 * {@see \TAW\CLI\CorpusInstallCommand}). Deliberately narrow and
 * purpose-built for this one schema, matching how
 * {@see \TAW\Core\Rag\KnowledgeBase\GenericSqliteIngestor} and
 * {@see \TAW\Core\Content\Exporter} are narrow rather than a general SQLite
 * query layer — this is read-only reference data, not application state.
 *
 * No WordPress dependency beyond {@see \TAW\Core\Corpus\Storage} resolving
 * the uploads directory — every method here is plain PDO.
 *
 * Schema surface actually read: `books`, `chapters`, `verses`, `sections`,
 * `notes`, `verses_fts`, `notes_fts`. `scripture_references` exists in the
 * schema (Straubinger's own cross-reference table) but isn't surfaced by
 * this reader yet — in the currently installed corpus it's populated for
 * only a handful of rows (the generating pipeline's own convention, not
 * ours; see the file's `meta` table), so there's nothing meaningful to
 * build against yet. Add a reader method for it once real data exists.
 *
 * Deliberately not `final`: {@see \TAW\Core\Rest\BibleEndpoint} resolves
 * which reader it queries through `apply_filters('taw_corpus_bible_reader',
 * new self())`, so a theme can swap in a differently-configured or
 * differently-behaved reader (override {@see self::FILENAME} via a child
 * class, or override individual fetch methods — all marked `protected`
 * for that reason) without forking taw-core. No separate interface for
 * this yet — one real consumer doesn't justify one; extract
 * `BibleReaderInterface` once a second implementation actually needs an
 * enforced contract instead of an inherited one.
 *
 * @phpstan-type Book array{id: int, slug: string, name: string, full_name: ?string, latin_name: ?string, abbreviation: string, canon: string, testament: string, division: ?string, book_order: int, chapter_count: int}
 * @phpstan-type Verse array{id: int, verse_number: int, verse_label: string, text: string, is_editorial_addition: bool}
 * @phpstan-type Section array{id: int, parent_id: ?int, kind: string, heading: string, subheading: ?string, body: ?string, start_chapter: int, start_verse: ?int, end_chapter: int, end_verse: ?int, position: int}
 * @phpstan-type Note array{id: int, type: string, marker: ?string, body: string, start_chapter: ?int, start_verse: ?int, end_chapter: ?int, end_verse: ?int, position: int}
 */
class BibleReader
{
    public const FILENAME = 'bible-straubinger.sqlite';

    private ?\PDO $pdo = null;

    public static function isInstalled(): bool
    {
        return is_file(Storage::dbPath(static::FILENAME));
    }

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Storage::openReadOnly(Storage::dbPath(static::FILENAME));
        }

        return $this->pdo;
    }

    /**
     * Every book grouped by testament, then by division — the shape a
     * books-list nav needs directly, without the caller re-deriving the
     * grouping from a flat list. Order follows `book_order` throughout
     * (already canonical: Pentateuco -> ... -> Apocalipsis).
     *
     * @return list<array{testament: string, divisions: list<array{division: ?string, books: list<Book>}>}>
     */
    public function books(): array
    {
        $rows = $this->pdo()->query(
            'SELECT b.id, b.slug, b.name, b.full_name, b.latin_name, b.abbreviation, b.canon,
                    b.testament, b.division, b.book_order, COUNT(c.id) AS chapter_count
             FROM books b
             LEFT JOIN chapters c ON c.book_id = b.id
             GROUP BY b.id
             ORDER BY b.book_order'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $testaments = [];
        foreach ($rows as $row) {
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

    /**
     * One chapter's verses, plus any section headings and any Straubinger
     * notes whose range overlaps it. Presentation (where a heading sits
     * relative to a verse, how a note marker anchors into verse text) is
     * deliberately left to the caller — `sections`/`notes` carry their own
     * `start_*`/`end_*` ranges for that, rather than this reader guessing
     * at an interleaved rendering shape.
     *
     * @return array{book: Book, chapter_number: int, verses: list<Verse>, sections: list<Section>, notes: list<Note>}|null
     */
    public function chapter(string $bookSlug, int $chapterNumber): ?array
    {
        $book = $this->fetchBookBySlug($bookSlug);
        if ($book === null) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT id FROM chapters WHERE book_id = :book_id AND chapter_number = :n');
        $stmt->execute(['book_id' => $book['id'], 'n' => $chapterNumber]);
        $chapterId = $stmt->fetchColumn();
        if ($chapterId === false) {
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

    /**
     * FTS5 search over verse text. The whole query is treated as one
     * literal phrase (quotes doubled to escape) rather than exposed as raw
     * FTS5 MATCH syntax — a public search box shouldn't hand end users an
     * operator DSL that can throw a syntax error on unbalanced input.
     *
     * @return list<array{id: int, verse_number: int, verse_label: string, text: string, excerpt: string, book_slug: string, book_name: string, book_abbreviation: string, chapter_number: int}>
     */
    public function searchVerses(string $query, int $limit = 20): array
    {
        if (trim($query) === '') {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            "SELECT v.id, v.verse_number, v.verse_label, v.text,
                    snippet(verses_fts, 0, '<mark>', '</mark>', '…', 10) AS excerpt,
                    b.slug AS book_slug, b.name AS book_name, b.abbreviation AS book_abbreviation,
                    c.chapter_number
             FROM verses_fts
             JOIN verses v ON v.id = verses_fts.rowid
             JOIN books b ON b.id = v.book_id
             JOIN chapters c ON c.id = v.chapter_id
             WHERE verses_fts MATCH :query
             ORDER BY rank
             LIMIT :limit"
        );
        $stmt->bindValue('query', self::escapeFtsPhrase($query), \PDO::PARAM_STR);
        $stmt->bindValue('limit', self::clampLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'verse_number' => (int) $r['verse_number'],
                'verse_label' => (string) $r['verse_label'],
                'text' => (string) $r['text'],
                'excerpt' => (string) $r['excerpt'],
                'book_slug' => (string) $r['book_slug'],
                'book_name' => (string) $r['book_name'],
                'book_abbreviation' => (string) $r['book_abbreviation'],
                'chapter_number' => (int) $r['chapter_number'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * FTS5 search over Straubinger's own footnote commentary. Same
     * literal-phrase escaping as {@see self::searchVerses()}.
     *
     * @return list<array{id: int, type: string, marker: ?string, excerpt: string, book_slug: string, book_name: string, start_chapter: ?int, start_verse: ?int}>
     */
    public function searchNotes(string $query, int $limit = 20): array
    {
        if (trim($query) === '') {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            "SELECT n.id, n.type, n.marker, n.start_chapter, n.start_verse,
                    snippet(notes_fts, 0, '<mark>', '</mark>', '…', 10) AS excerpt,
                    b.slug AS book_slug, b.name AS book_name
             FROM notes_fts
             JOIN notes n ON n.id = notes_fts.rowid
             JOIN books b ON b.id = n.book_id
             WHERE notes_fts MATCH :query
             ORDER BY rank
             LIMIT :limit"
        );
        $stmt->bindValue('query', self::escapeFtsPhrase($query), \PDO::PARAM_STR);
        $stmt->bindValue('limit', self::clampLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'type' => (string) $r['type'],
                'marker' => $r['marker'] !== null ? (string) $r['marker'] : null,
                'excerpt' => (string) $r['excerpt'],
                'book_slug' => (string) $r['book_slug'],
                'book_name' => (string) $r['book_name'],
                'start_chapter' => $r['start_chapter'] !== null ? (int) $r['start_chapter'] : null,
                'start_verse' => $r['start_verse'] !== null ? (int) $r['start_verse'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * @return Book|null
     */
    protected function fetchBookBySlug(string $slug): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT b.id, b.slug, b.name, b.full_name, b.latin_name, b.abbreviation, b.canon,
                    b.testament, b.division, b.book_order, COUNT(c.id) AS chapter_count
             FROM books b
             LEFT JOIN chapters c ON c.book_id = b.id
             WHERE b.slug = :slug
             GROUP BY b.id'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeBookRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return Book
     */
    protected function normalizeBookRow(array $row): array
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
     * @return list<Verse>
     */
    protected function fetchVerses(int $bookId, int $chapterId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, verse_number, verse_label, text, is_editorial_addition
             FROM verses
             WHERE book_id = :book_id AND chapter_id = :chapter_id
             ORDER BY verse_number'
        );
        $stmt->execute(['book_id' => $bookId, 'chapter_id' => $chapterId]);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'verse_number' => (int) $r['verse_number'],
                'verse_label' => (string) $r['verse_label'],
                'text' => (string) $r['text'],
                'is_editorial_addition' => (bool) $r['is_editorial_addition'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return list<Section>
     */
    protected function fetchSections(int $bookId, int $chapterNumber): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, parent_id, kind, heading, subheading, body, start_chapter, start_verse, end_chapter, end_verse, position
             FROM sections
             WHERE book_id = :book_id AND start_chapter <= :n AND end_chapter >= :n
             ORDER BY position'
        );
        $stmt->execute(['book_id' => $bookId, 'n' => $chapterNumber]);

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
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return list<Note>
     */
    protected function fetchNotes(int $bookId, int $chapterNumber): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, type, marker, body, start_chapter, start_verse, end_chapter, end_verse, position
             FROM notes
             WHERE book_id = :book_id AND start_chapter <= :n AND end_chapter >= :n
             ORDER BY position'
        );
        $stmt->execute(['book_id' => $bookId, 'n' => $chapterNumber]);

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
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    protected static function escapeFtsPhrase(string $query): string
    {
        return '"' . str_replace('"', '""', trim($query)) . '"';
    }

    protected static function clampLimit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
