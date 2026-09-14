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
 * `bin/taw catechism:export <sqlite-path> <json-output-path>`
 *
 * Dumps a catechism-schema `.sqlite` file (parts/sections/chapters/
 * paragraphs) to a portable JSON export `bin/taw catechism:install` can
 * import into MySQL storage — the escape hatch for installing on a host
 * whose `pdo_sqlite` isn't available, without ever needing to parse the
 * raw `.sqlite` file format on that target server. Mirrors
 * {@see CorpusExportCommand} (Bible's equivalent) exactly; kept as a
 * separate command rather than generalizing that one, since the two
 * corpus shapes don't overlap enough for a shared implementation to be
 * worth the indirection.
 *
 * Column aliases in the export queries below (`id AS source_id`, `"order"
 * AS part_order`, etc.) intentionally match {@see
 * \TAW\Core\Corpus\Catechism\MysqlCatechismSchema}'s own column names, so
 * {@see \TAW\Core\Corpus\Catechism\MysqlCatechismInstaller} can insert an
 * export row's fields directly with no separate field-mapping step.
 *
 * Run this on a machine that *does* have `pdo_sqlite` — practically always
 * a developer's local machine — then transfer the resulting JSON file to
 * the target server and run `catechism:install` there.
 */
class CatechismExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('catechism:export')
            ->setDescription('Export a catechism corpus .sqlite file to a portable JSON format for MySQL-backed install')
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
            $io->error('No working pdo_sqlite driver on this machine — catechism:export needs to read the source .sqlite file directly. Run this command on a machine that has pdo_sqlite (typically a developer\'s local machine), not on the target server.');
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
                'schema_version' => 'catechism-corpus-portable-1.0',
                'parts' => $pdo->query(
                    'SELECT id AS source_id, name, "order" AS part_order FROM parts ORDER BY "order"'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'sections' => $pdo->query(
                    'SELECT id AS source_id, part_id, title, "order" AS section_order FROM sections ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'chapters' => $pdo->query(
                    'SELECT id AS source_id, section_id, title, "order" AS chapter_order FROM chapters ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'paragraphs' => $pdo->query(
                    'SELECT id AS source_id, chapter_id, section_id, part_id, paragraph_number, question_text, answer_text FROM paragraphs ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
            ];
        } catch (\PDOException $e) {
            $io->error("Failed to read the corpus — does it match the expected catechism schema (parts/sections/chapters/paragraphs)? {$e->getMessage()}");
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
            'Exported %d parts, %d sections, %d chapters, %d paragraphs to %s (%s).',
            count($data['parts']),
            count($data['sections']),
            count($data['chapters']),
            count($data['paragraphs']),
            $outputPath,
            self::humanSize((int) filesize($outputPath))
        ));

        return Command::SUCCESS;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
