<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

use TAW\Core\Corpus\Storage;

/**
 * Read-only reader over an installed Code of Canon Law corpus
 * (`canon-law-{edition}.sqlite`, installed via `bin/taw canon-law:install`
 * — see {@see \TAW\CLI\CanonLawInstallCommand}). Same "narrow, plain PDO,
 * no WordPress beyond {@see Storage}" posture as
 * {@see \TAW\Core\Corpus\Catechism\CatechismReader}.
 *
 * Schema surface read: `divisions` (id, parent_id, kind, title, "order"),
 * `canons` (id, division_id, number, text), `canons_fts` (external-content
 * FTS5 over `text`), and the optional `meta` key/value table the upstream
 * proofreading app's `corpus:export` writes. Canon text is returned
 * through {@see CanonText::parse()} — never raw.
 *
 * Not `final`: a theme can swap in a differently-configured reader via
 * the `taw_corpus_canon_law_reader` filter (see
 * {@see \TAW\Core\Rest\CanonLawEndpoint::reader()}).
 *
 * @phpstan-import-type Division from CanonLawReaderInterface
 * @phpstan-import-type DivisionView from CanonLawReaderInterface
 * @phpstan-import-type LocatedCanon from CanonLawReaderInterface
 * @phpstan-import-type CanonSearchResult from CanonLawReaderInterface
 */
class CanonLawReader implements CanonLawReaderInterface
{
    /** @var array<string, \PDO> */
    private array $pdo = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $divisionRows = [];

    public static function isInstalled(string $edition): bool
    {
        $filename = CanonLawEditions::filename($edition);

        return $filename !== null && is_file(Storage::dbPath($filename));
    }

    protected function pdo(string $edition): \PDO
    {
        if (!isset($this->pdo[$edition])) {
            $filename = CanonLawEditions::filename($edition);
            if ($filename === null) {
                throw new \InvalidArgumentException("Unknown canon law edition '{$edition}'.");
            }

            $this->pdo[$edition] = Storage::openReadOnly(Storage::dbPath($filename));
        }

        return $this->pdo[$edition];
    }

    public function divisions(string $edition): array
    {
        if (!self::isInstalled($edition)) {
            return [];
        }

        return DivisionTree::build($this->divisionRows($edition));
    }

    public function division(string $edition, int $divisionId): ?array
    {
        if (!self::isInstalled($edition)) {
            return null;
        }

        $rows = $this->divisionRows($edition);
        $row = null;
        foreach ($rows as $candidate) {
            if ((int) $candidate['id'] === $divisionId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return null;
        }

        $stmt = $this->pdo($edition)->prepare(
            'SELECT number, text FROM canons WHERE division_id = :division_id ORDER BY number'
        );
        $stmt->execute(['division_id' => $divisionId]);
        $canons = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($canons === []) {
            return null;
        }

        return [
            'division' => DivisionTree::crumb($row),
            'breadcrumb' => DivisionTree::breadcrumb($rows, $divisionId),
            'canons' => array_map(self::canon(...), $canons),
        ];
    }

    public function canons(string $edition, array $numbers): array
    {
        $numbers = self::cleanNumbers($numbers);
        if ($numbers === [] || !self::isInstalled($edition)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $stmt = $this->pdo($edition)->prepare(
            "SELECT number, text, division_id FROM canons WHERE number IN ({$placeholders})"
        );
        $stmt->execute($numbers);

        $found = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['number']] = $row;
        }

        $rows = $this->divisionRows($edition);
        $result = [];
        foreach ($numbers as $number) {
            if (!isset($found[$number])) {
                continue;
            }
            $divisionId = (int) $found[$number]['division_id'];
            $result[] = self::canon($found[$number]) + [
                'division_id' => $divisionId,
                'breadcrumb' => [...DivisionTree::breadcrumb($rows, $divisionId), ...self::selfCrumb($rows, $divisionId)],
            ];
        }

        return $result;
    }

    /**
     * FTS5 search — same per-word-AND-as-quoted-phrases escaping as
     * {@see \TAW\Core\Corpus\Catechism\CatechismReader::searchParagraphs()}.
     */
    public function searchCanons(string $edition, string $query, int $limit = 20): array
    {
        if (!self::isInstalled($edition) || trim($query) === '') {
            return [];
        }

        $stmt = $this->pdo($edition)->prepare(
            "SELECT c.number, c.text, c.division_id, d.title AS division_title,
                    snippet(canons_fts, -1, '<mark>', '</mark>', '…', 14) AS excerpt
             FROM canons_fts
             JOIN canons c ON c.id = canons_fts.rowid
             JOIN divisions d ON d.id = c.division_id
             WHERE canons_fts MATCH :query
             ORDER BY rank
             LIMIT :limit"
        );
        $stmt->bindValue('query', self::escapeFtsPhrase($query), \PDO::PARAM_STR);
        $stmt->bindValue('limit', self::clampLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => [
                'number' => (int) $r['number'],
                'excerpt' => CanonText::cleanExcerpt((string) $r['excerpt']),
                'amended' => CanonText::parse((string) $r['text'])['amended'],
                'division_id' => (int) $r['division_id'],
                'division_title' => (string) $r['division_title'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function meta(string $edition): array
    {
        if (!self::isInstalled($edition)) {
            return [];
        }

        try {
            $rows = $this->pdo($edition)->query('SELECT "key", value FROM meta')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return []; // An export from before the meta table existed.
        }

        $meta = [];
        foreach ($rows as $row) {
            $meta[(string) $row['key']] = (string) $row['value'];
        }

        return $meta;
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * Every division with its direct canon count and number range — one
     * small query (a few hundred rows), cached per edition per request,
     * since the tree, breadcrumbs, and lookups all need it.
     *
     * @return list<array<string, mixed>>
     */
    private function divisionRows(string $edition): array
    {
        if (!isset($this->divisionRows[$edition])) {
            $this->divisionRows[$edition] = $this->pdo($edition)->query(
                'SELECT d.id, d.parent_id, d.kind, d.title, d."order" AS "order",
                        COUNT(c.id) AS canon_count, MIN(c.number) AS canon_min, MAX(c.number) AS canon_max
                 FROM divisions d
                 LEFT JOIN canons c ON c.division_id = d.id
                 GROUP BY d.id'
            )->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $this->divisionRows[$edition];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, kind: string, title: string}>
     */
    private static function selfCrumb(array $rows, int $divisionId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $divisionId) {
                return [DivisionTree::crumb($row)];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{number: int, text: string, amended: bool, amendment: array{note: string, original_text: string|null}|null}
     */
    private static function canon(array $row): array
    {
        return ['number' => (int) $row['number']] + CanonText::parse((string) $row['text']);
    }

    /**
     * @param array<mixed> $numbers
     * @return list<int>
     */
    private static function cleanNumbers(array $numbers): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $numbers),
            static fn (int $n): bool => $n > 0
        )));

        return array_slice($clean, 0, 50);
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
