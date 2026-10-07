<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * MySQL-backed mirror of {@see CanonLawReader}, selected by
 * {@see \TAW\Core\Rest\CanonLawEndpoint} when `pdo_sqlite` isn't available
 * (the production host has none — see ADR-0002). Every query filters on
 * `edition` alongside its `source_id` join, and search reuses
 * {@see \TAW\Core\Corpus\Catechism\MysqlCatechismReader}'s
 * `innodb_ft_min_token_size` short-word filtering and hand-built excerpts
 * (MySQL has no `snippet()`).
 *
 * @phpstan-import-type Division from CanonLawReaderInterface
 * @phpstan-import-type DivisionView from CanonLawReaderInterface
 * @phpstan-import-type LocatedCanon from CanonLawReaderInterface
 * @phpstan-import-type CanonSearchResult from CanonLawReaderInterface
 */
class MysqlCanonLawReader implements CanonLawReaderInterface
{
    private const EXCERPT_RADIUS = 90;

    /** @var array<string, list<array<string, mixed>>> */
    private array $divisionRows = [];

    public static function isInstalled(string $edition): bool
    {
        global $wpdb;

        $table = MysqlCanonLawSchema::canons();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return false;
        }

        return ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition = %s",
            $edition
        ))) > 0;
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
        global $wpdb;

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

        $canons = MysqlCanonLawSchema::canons();
        $canonRows = $wpdb->get_results($wpdb->prepare(
            "SELECT number, text FROM {$canons} WHERE edition = %s AND division_id = %d ORDER BY number",
            $edition,
            $divisionId
        ), ARRAY_A);

        if ($canonRows === null || $canonRows === []) {
            return null;
        }

        return [
            'division' => DivisionTree::crumb($row),
            'breadcrumb' => DivisionTree::breadcrumb($rows, $divisionId),
            'canons' => array_map(self::canon(...), (array) $canonRows),
        ];
    }

    public function canons(string $edition, array $numbers): array
    {
        global $wpdb;

        $numbers = array_slice(array_values(array_unique(array_filter(
            array_map('intval', $numbers),
            static fn (int $n): bool => $n > 0
        ))), 0, 50);

        if ($numbers === [] || !self::isInstalled($edition)) {
            return [];
        }

        $canons = MysqlCanonLawSchema::canons();
        $placeholders = implode(',', array_fill(0, count($numbers), '%d'));
        $found = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT number, text, division_id FROM {$canons} WHERE edition = %s AND number IN ({$placeholders})",
            $edition,
            ...$numbers
        ), ARRAY_A) as $row) {
            $found[(int) $row['number']] = $row;
        }

        $rows = $this->divisionRows($edition);
        $result = [];
        foreach ($numbers as $number) {
            if (!isset($found[$number])) {
                continue;
            }
            $divisionId = (int) $found[$number]['division_id'];
            $self = [];
            foreach ($rows as $row) {
                if ((int) $row['id'] === $divisionId) {
                    $self = [DivisionTree::crumb($row)];
                    break;
                }
            }
            $result[] = self::canon($found[$number]) + [
                'division_id' => $divisionId,
                'breadcrumb' => [...DivisionTree::breadcrumb($rows, $divisionId), ...$self],
            ];
        }

        return $result;
    }

    public function searchCanons(string $edition, string $query, int $limit = 20): array
    {
        global $wpdb;

        if (!self::isInstalled($edition)) {
            return [];
        }

        $words = self::queryWords($query);
        $significant = self::significantWords($words);
        if ($significant === []) {
            return [];
        }

        $canons = MysqlCanonLawSchema::canons();
        $divisions = MysqlCanonLawSchema::divisions();
        $boolean = implode(' ', array_map(static fn (string $w): string => '+' . $w, $significant));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.number, c.text, c.division_id, d.title AS division_title
             FROM {$canons} c
             JOIN {$divisions} d ON d.edition = c.edition AND d.source_id = c.division_id
             WHERE c.edition = %s
               AND MATCH(c.text) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY MATCH(c.text) AGAINST (%s IN BOOLEAN MODE) DESC
             LIMIT %d",
            $edition,
            $boolean,
            $boolean,
            max(1, min(50, $limit))
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'number' => (int) $r['number'],
                'excerpt' => CanonText::cleanExcerpt(self::buildExcerpt((string) $r['text'], $words)),
                'amended' => CanonText::parse((string) $r['text'])['amended'],
                'division_id' => (int) $r['division_id'],
                'division_title' => (string) $r['division_title'],
            ],
            (array) $rows
        );
    }

    public function meta(string $edition): array
    {
        global $wpdb;

        if (!self::isInstalled($edition)) {
            return [];
        }

        $table = MysqlCanonLawSchema::meta();
        $meta = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$table} WHERE edition = %s",
            $edition
        ), ARRAY_A) as $row) {
            $meta[(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        return $meta;
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    private function divisionRows(string $edition): array
    {
        global $wpdb;

        if (!isset($this->divisionRows[$edition])) {
            $divisions = MysqlCanonLawSchema::divisions();
            $canons = MysqlCanonLawSchema::canons();

            $this->divisionRows[$edition] = array_values((array) $wpdb->get_results($wpdb->prepare(
                "SELECT d.source_id AS id, d.parent_id, d.kind, d.title, d.division_order AS `order`,
                        COUNT(c.id) AS canon_count, MIN(c.number) AS canon_min, MAX(c.number) AS canon_max
                 FROM {$divisions} d
                 LEFT JOIN {$canons} c ON c.edition = d.edition AND c.division_id = d.source_id
                 WHERE d.edition = %s
                 GROUP BY d.id",
                $edition
            ), ARRAY_A));
        }

        return $this->divisionRows[$edition];
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
     * Words shorter than `innodb_ft_min_token_size` were never indexed —
     * requiring one (`+de`) would make every query containing it return
     * nothing. See MysqlBibleReader's docblock for the production incident.
     *
     * @param list<string> $words
     * @return list<string>
     */
    private static function significantWords(array $words): array
    {
        global $wpdb;

        $value = $wpdb->get_var('SELECT @@innodb_ft_min_token_size');
        $minLength = $value !== null ? (int) $value : 3;

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= $minLength));
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

        $pattern = implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words));
        $highlighted = $pattern === ''
            ? $snippet
            : (preg_replace('/(' . $pattern . ')/iu', '<mark>$1</mark>', $snippet) ?? $snippet);

        return $prefix . $highlighted . $suffix;
    }
}
