<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

/**
 * Generic CSV/JSON -> reference-DB importer, shared by the `bible` and
 * `catechism` schemas (and any future reference schema) via
 * {@see ReferenceSchema}. No ABSPATH guard — see that class's docblock.
 *
 * Rows are matched by natural key (never row id) and upserted: unseen keys
 * are inserted, seen keys with changed column values are updated, seen
 * keys with identical values are left alone. Re-importing the same file
 * twice is therefore a true no-op the second time — nothing reported as
 * "updated" unless something actually changed.
 */
final class ReferenceImporter
{
    /**
     * @return list<array<string, string>>
     */
    public function parse(string $path, ReferenceSchema $schema): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $rows = match ($ext) {
            'csv' => $this->parseCsv($path),
            'json' => $this->parseJson($path),
            default => throw new \InvalidArgumentException("Unsupported file extension '.{$ext}' — use .csv or .json."),
        };

        $missing = $rows === [] ? [] : array_diff($schema->columns, array_keys($rows[0]));
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                "File is missing column(s) expected by the '%s' schema: %s.",
                $schema->name,
                implode(', ', $missing)
            ));
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public function plan(\PDO $pdo, ReferenceSchema $schema, array $rows): ReferenceImportReport
    {
        return $this->process($pdo, $schema, $rows, apply: false);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public function apply(\PDO $pdo, ReferenceSchema $schema, array $rows): ReferenceImportReport
    {
        return $this->process($pdo, $schema, $rows, apply: true);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$path}");
        }

        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = array_map(static fn (?string $c): string => trim((string) $c), $header);

        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = trim((string) ($line[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Could not read file: {$path}");
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new \RuntimeException('JSON file must decode to an array of row objects.');
        }

        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = trim((string) $value);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function process(\PDO $pdo, ReferenceSchema $schema, array $rows, bool $apply): ReferenceImportReport
    {
        $updatableColumns = array_values(array_diff($schema->columns, $schema->naturalKey));

        $selectStmt = $pdo->prepare(sprintf(
            'SELECT id%s FROM %s WHERE %s',
            $updatableColumns === [] ? '' : ', ' . implode(', ', $updatableColumns),
            $schema->table,
            implode(' AND ', array_map(static fn (string $c): string => "{$c} = :{$c}", $schema->naturalKey))
        ));

        $insertStmt = $pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $schema->table,
            implode(', ', $schema->columns),
            implode(', ', array_map(static fn (string $c): string => ":{$c}", $schema->columns))
        ));

        $updateStmt = $updatableColumns === [] ? null : $pdo->prepare(sprintf(
            'UPDATE %s SET %s WHERE id = :id',
            $schema->table,
            implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $updatableColumns))
        ));

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $index => $row) {
            $keyParams = [];
            $missing = [];
            foreach ($schema->naturalKey as $col) {
                $value = trim((string) ($row[$col] ?? ''));
                if ($value === '') {
                    $missing[] = $col;
                }
                $keyParams[$col] = $value;
            }
            if ($missing !== []) {
                $warnings[] = sprintf(
                    'Row %d: missing/empty natural-key column(s) %s — skipped.',
                    $index + 1,
                    implode(', ', $missing)
                );
                $skipped++;
                continue;
            }

            $allParams = [];
            foreach ($schema->columns as $col) {
                $allParams[$col] = (string) ($row[$col] ?? '');
            }

            $selectStmt->execute($keyParams);
            $existing = $selectStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing === false) {
                $inserted++;
                if ($apply) {
                    $insertStmt->execute($allParams);
                }
                continue;
            }

            $changed = false;
            foreach ($updatableColumns as $col) {
                if (($existing[$col] ?? '') !== $allParams[$col]) {
                    $changed = true;
                    break;
                }
            }

            if (!$changed) {
                $skipped++;
                continue;
            }

            $updated++;
            if ($apply && $updateStmt !== null) {
                $updateParams = array_intersect_key($allParams, array_flip($updatableColumns));
                $updateParams['id'] = $existing['id'];
                $updateStmt->execute($updateParams);
            }
        }

        return new ReferenceImportReport($inserted, $updated, $skipped, $warnings);
    }
}
