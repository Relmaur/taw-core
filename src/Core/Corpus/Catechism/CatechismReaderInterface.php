<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

/**
 * The public contract {@see CatechismReader} (SQLite-backed) and
 * {@see MysqlCatechismReader} (MySQL-backed, used when `pdo_sqlite` isn't
 * available — see `TAW\Core\Storage\ProtectedSqlite::isAvailable()`) both
 * implement, so {@see \TAW\Core\Rest\CatechismEndpoint} can query either
 * transparently. Mirrors {@see \TAW\Core\Corpus\Bible\BibleReaderInterface}
 * one level down in hierarchy depth — a catechism's natural navigation is
 * part → section → chapter → numbered question/answer, one deeper than
 * Scripture's book → chapter → verse.
 *
 * Every method takes an `$edition` slug (resolved via
 * {@see CatechismEditions}) rather than assuming a single installed
 * catechism the way {@see \TAW\Core\Corpus\Bible\BibleReader} assumes a
 * single installed Bible — this is what lets a second catechism edition
 * (e.g. Saint John Paul II's) be added later without a new reader class.
 *
 * @phpstan-type Chapter array{id: int, title: string, order: int, paragraph_count: int}
 * @phpstan-type Section array{id: int, title: string, order: int, chapters: list<Chapter>}
 * @phpstan-type Part array{id: int, name: string, order: int, sections: list<Section>}
 * @phpstan-type Paragraph array{id: int, paragraph_number: int, question_text: ?string, answer_text: string}
 * @phpstan-type ChapterView array{part: array{id: int, name: string}, section: array{id: int, title: string}, chapter: array{id: int, title: string}, paragraphs: list<Paragraph>}
 * @phpstan-type ParagraphSearchResult array{id: int, paragraph_number: int, question_text: ?string, excerpt: string, chapter_id: int, chapter_title: string, section_title: string, part_name: string}
 */
interface CatechismReaderInterface
{
    /**
     * The full navigable tree for one edition — parts containing sections
     * containing chapters, each chapter carrying its own paragraph count
     * so a caller can render "N preguntas" without a second request.
     * Implementations filter out any part/section/chapter that ends up
     * with zero paragraphs under it (a source export can carry unrelated
     * or not-yet-populated structure — see {@see CatechismReader}'s own
     * docblock) so callers never have to defend against empty branches.
     *
     * @return list<Part>
     */
    public function parts(string $edition): array;

    /**
     * One chapter's question/answer paragraphs, plus its part/section
     * breadcrumb — the reading unit this whole subsystem paginates by,
     * the same role {@see \TAW\Core\Corpus\Bible\BibleReaderInterface::chapter()}
     * plays for verses.
     *
     * @return ChapterView|null
     */
    public function chapter(string $edition, int $chapterId): ?array;

    /**
     * Full-text search over both the question and answer text of every
     * paragraph in one edition.
     *
     * @return list<ParagraphSearchResult>
     */
    public function searchParagraphs(string $edition, string $query, int $limit = 20): array;
}
