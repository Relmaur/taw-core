<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Bible;

/**
 * The public contract {@see BibleReader} (SQLite-backed) and
 * {@see MysqlBibleReader} (MySQL-backed, used when `pdo_sqlite` isn't
 * available — see `TAW\Core\Storage\ProtectedSqlite::isAvailable()`) both
 * implement, so {@see \TAW\Core\Rest\BibleEndpoint} can query either
 * transparently.
 *
 * Extracted now — this codebase's own convention (see
 * docs/adr/0001-reference-corpus-storage.md) is not to build an interface
 * until a second real implementation exists to shape it against; that
 * point has now arrived.
 *
 * @phpstan-type Book array{id: int, slug: string, name: string, full_name: ?string, latin_name: ?string, abbreviation: string, canon: string, testament: string, division: ?string, book_order: int, chapter_count: int}
 * @phpstan-type Verse array{id: int, verse_number: int, verse_label: string, text: string, is_editorial_addition: bool}
 * @phpstan-type Section array{id: int, parent_id: ?int, kind: string, heading: string, subheading: ?string, body: ?string, start_chapter: int, start_verse: ?int, end_chapter: int, end_verse: ?int, position: int}
 * @phpstan-type Note array{id: int, type: string, marker: ?string, body: string, start_chapter: ?int, start_verse: ?int, end_chapter: ?int, end_verse: ?int, position: int}
 * @phpstan-type VerseSearchResult array{id: int, verse_number: int, verse_label: string, text: string, excerpt: string, book_slug: string, book_name: string, book_abbreviation: string, chapter_number: int}
 * @phpstan-type NoteSearchResult array{id: int, type: string, marker: ?string, excerpt: string, book_slug: string, book_name: string, start_chapter: ?int, start_verse: ?int}
 */
interface BibleReaderInterface
{
    /**
     * @return list<array{testament: string, divisions: list<array{division: ?string, books: list<Book>}>}>
     */
    public function books(): array;

    /**
     * @return array{book: Book, chapter_number: int, verses: list<Verse>, sections: list<Section>, notes: list<Note>}|null
     */
    public function chapter(string $bookSlug, int $chapterNumber): ?array;

    /**
     * @return list<VerseSearchResult>
     */
    public function searchVerses(string $query, int $limit = 20): array;

    /**
     * @return list<NoteSearchResult>
     */
    public function searchNotes(string $query, int $limit = 20): array;
}
