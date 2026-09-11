<?php

declare(strict_types=1);

namespace TAW\Tests\Support;

/**
 * A minimal `$wpdb`-shaped test double for exercising `$wpdb`-touching
 * classes (currently `MysqlBibleReader`/`MysqlBibleInstaller`) without a
 * real MySQL server, which this suite deliberately doesn't depend on (see
 * tests/bootstrap.php's own docblock on the fast-isolated-logic vs
 * real-end-to-end-smoke-test split).
 *
 * Backed by a real in-memory SQLite connection for SELECT/INSERT/
 * TRUNCATE — the ANSI-ish subset `MysqlBibleReader`'s non-search queries
 * use runs unchanged against SQLite. `MATCH(col) AGAINST ('...' IN
 * BOOLEAN MODE)` (MySQL FULLTEXT, no SQLite equivalent) is translated
 * into an AND-of-LIKEs approximation — enough to exercise the reader's
 * actual word-splitting/result-shaping/excerpt-building logic
 * end-to-end, without claiming to validate real MySQL FULLTEXT ranking
 * or tokenization semantics. `recordedQueries` additionally lets a test
 * assert on the exact SQL issued (used by MysqlBibleInstallerTest to
 * verify escaping without needing MySQL-flavored DDL to actually run).
 *
 * @phpstan-type Row array<string, mixed>
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';

    /**
     * Simulated `innodb_ft_min_token_size` — real MySQL's own default,
     * overridable per test to exercise a differently-configured host.
     */
    public int $innodbFtMinTokenSize = 3;

    /**
     * Mirrors real `$wpdb::$last_error`, set whenever query() "fails"
     * (either because it matches failOnQueryContaining, or because the
     * underlying SQLite engine itself raised on the translated SQL).
     */
    public string $last_error = '';

    /**
     * When set, any query() call whose SQL contains this substring
     * returns false (with last_error populated) instead of executing —
     * lets a test simulate a real $wpdb query failure (permissions, a
     * dropped connection, ...) without needing SQLite to actually fail.
     */
    public ?string $failOnQueryContaining = null;

    /** @var list<string> */
    public array $recordedQueries = [];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Lets a test create the SQLite-flavored tables `MysqlBibleReader`'s
     * SELECT statements expect (real MySQL DDL — TINYINT/MEDIUMTEXT/
     * FULLTEXT KEY/ENGINE=InnoDB — isn't valid SQLite syntax, so tests
     * create simpler equivalent tables directly rather than running
     * `MysqlBibleSchema::createStatements()` through this fake).
     */
    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    /**
     * Approximates `wpdb::prepare()`'s `%s`/`%d`/`%f` substitution.
     * Accepts either variadic args or a single array of args, matching
     * `wpdb::prepare()`'s own calling convention.
     */
    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $i = 0;

        return (string) preg_replace_callback('/%[sdf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i] ?? null;
            $i++;

            if ($value === null) {
                return 'NULL';
            }
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            if ($m[0] === '%f') {
                return (string) (float) $value;
            }

            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
    }

    /**
     * @return list<Row>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $stmt = $this->pdo->query($this->translate($query));

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return Row|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        $stmt = $this->pdo->query($this->translate($query));
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function get_var(string $query): mixed
    {
        if (trim($query) === 'SELECT @@innodb_ft_min_token_size') {
            return $this->innodbFtMinTokenSize;
        }

        $stmt = $this->pdo->query($this->translate($query));
        $value = $stmt === false ? false : $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    public function query(string $query): int|bool
    {
        $this->recordedQueries[] = $query;

        if ($this->failOnQueryContaining !== null && str_contains($query, $this->failOnQueryContaining)) {
            $this->last_error = "Simulated failure: query contains '{$this->failOnQueryContaining}'";

            return false;
        }

        try {
            $result = $this->pdo->exec($this->translate($query));
            $this->last_error = '';

            return $result;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
    }

    private function translate(string $sql): string
    {
        if (strcasecmp(trim($sql), 'START TRANSACTION') === 0) {
            return 'BEGIN';
        }

        if (preg_match('/^TRUNCATE TABLE (\S+)$/i', trim($sql), $m) === 1) {
            return "DELETE FROM {$m[1]}";
        }

        if (preg_match('/^SHOW TABLES LIKE \'([^\']*)\'$/i', trim($sql), $m) === 1) {
            return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$m[1]}'";
        }

        if (preg_match('/^CREATE TABLE/i', trim($sql)) === 1) {
            $sql = (string) preg_replace('/,\s*FULLTEXT KEY \w+\s*\([^)]*\)/i', '', $sql);
            $sql = (string) preg_replace('/,\s*KEY \w+\s*\([^)]*\)/i', '', $sql);
            $sql = (string) preg_replace('/\)\s*ENGINE=InnoDB\s*$/i', ')', trim($sql));

            return $sql;
        }

        return (string) preg_replace_callback(
            '/MATCH\(([a-zA-Z0-9_.]+)\)\s*AGAINST\s*\(\'([^\']*)\'\s*IN BOOLEAN MODE\)/i',
            static function (array $m): string {
                $column = $m[1];
                $words = array_filter(array_map(
                    static fn (string $w): string => ltrim($w, '+'),
                    explode(' ', $m[2])
                ));

                if ($words === []) {
                    return '1=1';
                }

                $conditions = array_map(
                    static fn (string $w): string => "{$column} LIKE '%" . str_replace("'", "''", $w) . "%'",
                    $words
                );

                return '(' . implode(' AND ', $conditions) . ')';
            },
            $sql
        );
    }
}
