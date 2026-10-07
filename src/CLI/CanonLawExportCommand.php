<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Storage\ProtectedSqlite;

/**
 * `bin/taw canon-law:export <sqlite-path> <json-output-path>`
 *
 * Dumps a canon-law `.sqlite` export (divisions/canons/meta) to the
 * portable JSON `bin/taw canon-law:install` imports into MySQL — the
 * escape hatch for a host without `pdo_sqlite`, mirroring
 * {@see CatechismExportCommand}. Run it where `pdo_sqlite` exists
 * (a developer machine), then install the JSON on the server.
 *
 * Column aliases match {@see \TAW\Core\Corpus\CanonLaw\MysqlCanonLawSchema}
 * so the installer inserts rows with no field mapping. The `meta` table is
 * carried along verbatim (provenance: `surface`, `release_channel`,
 * `source_revision`, …) — the installer refuses a file whose `surface`
 * isn't `canon_law`.
 */
class CanonLawExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('canon-law:export')
            ->setDescription('Export a canon law corpus .sqlite file to a portable JSON format for MySQL-backed install')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the source .sqlite file')
            ->addArgument('output', InputArgument::REQUIRED, 'Path to write the JSON export to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourcePath = (string) $input->getArgument('path');
        $outputPath = (string) $input->getArgument('output');

        if (!is_file($sourcePath)) {
            $io->error("No file found at '{$sourcePath}'.");
            return Command::FAILURE;
        }

        if (!ProtectedSqlite::isAvailable()) {
            $io->error('No working pdo_sqlite driver on this machine — canon-law:export needs to read the source .sqlite file directly. Run it on a machine that has pdo_sqlite (typically a developer\'s local machine), not on the target server.');
            return Command::FAILURE;
        }

        if (!ProtectedSqlite::looksLikeSqliteFile($sourcePath)) {
            $io->error("'{$sourcePath}' does not look like a SQLite database file.");
            return Command::FAILURE;
        }

        $pdo = new \PDO('sqlite:' . $sourcePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        try {
            $data = [
                'schema_version' => 'canon-law-corpus-portable-1.0',
                'meta' => self::readMeta($pdo),
                'divisions' => $pdo->query(
                    'SELECT id AS source_id, parent_id, kind, title, "order" AS division_order FROM divisions ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'canons' => $pdo->query(
                    'SELECT id AS source_id, division_id, number, text FROM canons ORDER BY number'
                )->fetchAll(\PDO::FETCH_ASSOC),
            ];
        } catch (\PDOException $e) {
            $io->error("Failed to read the corpus — does it match the canon law schema (divisions/canons)? {$e->getMessage()}");
            return Command::FAILURE;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $io->error('Failed to encode the export as JSON: ' . json_last_error_msg());
            return Command::FAILURE;
        }

        if (file_put_contents($outputPath, $json) === false) {
            $io->error("Failed to write to '{$outputPath}'.");
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Exported %d divisions, %d canons to %s (%s KB).',
            count($data['divisions']),
            count($data['canons']),
            $outputPath,
            round(((int) filesize($outputPath)) / 1024, 1)
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    public static function readMeta(\PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT "key", value FROM meta')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        $meta = [];
        foreach ($rows as $row) {
            $meta[(string) $row['key']] = $row['value'] === null ? null : (string) $row['value'];
        }

        return $meta;
    }
}
