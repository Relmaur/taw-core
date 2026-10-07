<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * The public contract {@see CanonLawReader} (SQLite) and
 * {@see MysqlCanonLawReader} (MySQL fallback) both implement, so
 * {@see \TAW\Core\Rest\CanonLawEndpoint} and
 * {@see \TAW\Core\Rag\Tools\CanonLawLookupTool} can query either
 * transparently — same split as the Bible and Catechism corpora.
 *
 * Unlike the Catechism's fixed part → section → chapter depth, a Code's
 * divisions nest to any depth (libro → parte → sección → título →
 * capítulo → artículo, with levels skipped freely), so the tree is a
 * recursive `children` list. Canons can hang off any level, not only a
 * leaf — Book I's cc. 1–6 sit directly on the Book, before its first
 * Title — so every division carries its own direct `canon_count` next to
 * its whole-subtree `canon_from`/`canon_to` range. The reading unit is a
 * division's *own* canons ({@see self::division()}); the canon number is
 * the citation unit ({@see self::canons()}).
 *
 * Every method takes an `$edition` slug resolved via {@see CanonLawEditions}.
 *
 * @phpstan-import-type Amendment from CanonText
 * @phpstan-type Division array{id: int, kind: string, title: string, order: int, canon_count: int, canon_from: int, canon_to: int, children: list<array<string, mixed>>}
 * @phpstan-type Crumb array{id: int, kind: string, title: string}
 * @phpstan-type Canon array{number: int, text: string, amended: bool, amendment: Amendment|null}
 * @phpstan-type DivisionView array{division: Crumb, breadcrumb: list<Crumb>, canons: list<Canon>}
 * @phpstan-type LocatedCanon array{number: int, text: string, amended: bool, amendment: Amendment|null, division_id: int, breadcrumb: list<Crumb>}
 * @phpstan-type CanonSearchResult array{number: int, excerpt: string, amended: bool, division_id: int, division_title: string}
 */
interface CanonLawReaderInterface
{
    /**
     * The full division tree. Divisions with no canon anywhere under them
     * are pruned, so callers never render a dead-end branch.
     *
     * @return list<Division>
     */
    public function divisions(string $edition): array;

    /**
     * One division's own canons (not its descendants'), in canon order,
     * plus its ancestor breadcrumb (root first, excluding itself). Null
     * when the division doesn't exist or has no canons of its own.
     *
     * @return DivisionView|null
     */
    public function division(string $edition, int $divisionId): ?array;

    /**
     * Canons by number, each with its division and breadcrumb — in the
     * order requested, unknown numbers skipped.
     *
     * @param list<int> $numbers
     * @return list<LocatedCanon>
     */
    public function canons(string $edition, array $numbers): array;

    /**
     * Full-text search over canon text.
     *
     * @return list<CanonSearchResult>
     */
    public function searchCanons(string $edition, string $query, int $limit = 20): array;

    /**
     * The export's own provenance (`release_channel`, `source_revision`,
     * `generated_at`, …) — empty when the installed file predates the
     * `meta` table.
     *
     * @return array<string, string>
     */
    public function meta(string $edition): array;
}
