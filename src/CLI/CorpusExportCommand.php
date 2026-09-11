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
 * `bin/taw corpus:export <sqlite-path> <json-output-path>`
 *
 * Dumps a Straubinger-schema Bible corpus `.sqlite` file to a portable
 * JSON export `bin/taw corpus:install` can import into MySQL storage —
 * the escape hatch for installing on a host whose `pdo_sqlite` isn't
 * available (confirmed to happen on real managed hosting), without ever
 * needing to parse the raw `.sqlite` file format on that target server.
 *
 * Run this on a machine that *does* have `pdo_sqlite` — practically
 * always a developer's local machine — then transfer the resulting JSON
 * file to the target server and run `corpus:install` there.
 *
 * No WordPress dependency at all: both paths are given directly as CLI
 * arguments, so there's nothing here that needs `Corpus\Storage`'s
 * uploads-directory resolution or any other WP-booted state.
 */
class CorpusExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('corpus:export')
            ->setDescription('Export a Bible corpus .sqlite file to a portable JSON format for MySQL-backed install')
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
            $io->error('No working pdo_sqlite driver on this machine — corpus:export needs to read the source .sqlite file directly. Run this command on a machine that has pdo_sqlite (typically a developer\'s local machine), not on the target server.');
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
                'schema_version' => 'bible-corpus-portable-1.0',
                'books' => $pdo->query(
                    'SELECT id, slug, name, full_name, latin_name, abbreviation, canon, testament, division, book_order FROM books ORDER BY book_order'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'chapters' => $pdo->query(
                    'SELECT id, book_id, chapter_number FROM chapters ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'verses' => $pdo->query(
                    'SELECT id, book_id, chapter_id, verse_number, verse_label, text, is_editorial_addition FROM verses ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'sections' => $pdo->query(
                    'SELECT id, book_id, parent_id, kind, heading, subheading, body, start_chapter, start_verse, end_chapter, end_verse, position FROM sections ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
                'notes' => $pdo->query(
                    'SELECT id, book_id, type, marker, body, start_chapter, start_verse, end_chapter, end_verse, position FROM notes ORDER BY id'
                )->fetchAll(\PDO::FETCH_ASSOC),
            ];
        } catch (\PDOException $e) {
            $io->error("Failed to read the corpus — does it match the expected Straubinger schema (books/chapters/verses/sections/notes)? {$e->getMessage()}");
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
            'Exported %d books, %d chapters, %d verses, %d sections, %d notes to %s (%s).',
            count($data['books']),
            count($data['chapters']),
            count($data['verses']),
            count($data['sections']),
            count($data['notes']),
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
