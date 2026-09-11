<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Corpus\Bible\MysqlBibleInstaller;
use TAW\Core\Corpus\Storage;
use TAW\Core\Storage\ProtectedSqlite;

/**
 * `bin/taw corpus:install <path> <filename>`
 *
 * Installs a developer-curated reference corpus (e.g. the Straubinger
 * Bible) from either of two source formats:
 *
 *  - A raw `.sqlite` file — copied into the protected `taw-private/corpus`
 *    directory (see {@see \TAW\Core\Corpus\Storage}) under `filename`, the
 *    same as always. Requires a working `pdo_sqlite` driver *on this
 *    machine* (see {@see ProtectedSqlite::isAvailable()}) — confirmed
 *    absent on some real managed hosting (WPMUdev declines to add it),
 *    where this now fails with a clear error instead of a silent
 *    `PDOException` from deep inside the reader.
 *  - A portable JSON export (see {@see \TAW\CLI\CorpusExportCommand}) —
 *    imported into MySQL tables via `$wpdb`
 *    ({@see MysqlBibleInstaller}), which needs no `pdo_sqlite` on this
 *    machine at all. `filename` is accepted but unused for this path
 *    (there's no destination file — MySQL tables have fixed names).
 *
 * `TAW\Core\Corpus\Bible\BibleReaderInterface` consumers (chiefly
 * {@see \TAW\Core\Rest\BibleEndpoint}) select between the two installed
 * results transparently — see that class's `reader()` resolution.
 *
 * Deliberately a CLI install step, not a wp-admin upload form: this is a
 * curated dataset a developer places once per environment, not something
 * an end-user is expected to swap through a web UI the way an admin
 * uploads a RAG knowledge base (see
 * {@see \TAW\Core\Rag\KnowledgeBase\KnowledgeBaseAdminScreen}). An upload
 * screen can be added later if that assumption turns out wrong.
 *
 * The `.sqlite` path copies rather than moves the source file, so
 * re-running this command against the same source is always safe; the
 * JSON path truncates and reloads its MySQL tables for the same reason.
 */
class CorpusInstallCommand extends Command
{
    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('corpus:install')
            ->setDescription('Install a reference corpus (.sqlite file or a portable JSON export) into storage')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the source .sqlite file or JSON export')
            ->addArgument('filename', InputArgument::REQUIRED, 'Destination filename for a .sqlite source, e.g. bible-straubinger.sqlite (unused for a JSON source)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourcePath = (string) $input->getArgument('path');
        $filename = (string) $input->getArgument('filename');

        if (!is_file($sourcePath)) {
            $io->error("No file found at '{$sourcePath}'.");
            return Command::FAILURE;
        }

        if (ProtectedSqlite::looksLikeSqliteFile($sourcePath)) {
            return $this->installSqlite($sourcePath, $filename, $io);
        }

        $portable = $this->decodePortableExport($sourcePath);
        if ($portable !== null) {
            return $this->installMysql($portable, $io);
        }

        $io->error("'{$sourcePath}' is neither a SQLite database file nor a recognized portable corpus export (expected JSON with book/chapter/verse/section/note arrays).");
        return Command::FAILURE;
    }

    /* -----------------------------------------------------------------
     * .sqlite source — protected-directory file copy
     * ----------------------------------------------------------------- */

    private function installSqlite(string $sourcePath, string $filename, SymfonyStyle $io): int
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.sqlite$/', $filename)) {
            $io->error("'{$filename}' must be a bare .sqlite filename (letters, digits, '-', '_', '.' only, no path segments).");
            return Command::FAILURE;
        }

        if (!ProtectedSqlite::isAvailable()) {
            $io->error(
                "This host has no working pdo_sqlite driver, so a raw .sqlite corpus can't be installed here. " .
                "Run `bin/taw corpus:export {$sourcePath} <output.json>` on a machine that does (typically a " .
                "developer's local machine), then `bin/taw corpus:install <output.json> {$filename}` on this host instead."
            );
            return Command::FAILURE;
        }

        if (!$this->bootWordPress($io)) {
            return Command::FAILURE;
        }

        Storage::ensureProtectedDir(Storage::dir());
        $destPath = Storage::dbPath($filename);

        if (!copy($sourcePath, $destPath)) {
            $io->error("Failed to copy the file to '{$destPath}'.");
            return Command::FAILURE;
        }

        $io->success(sprintf('Installed %s (%s) as SQLite storage.', $filename, self::humanSize((int) filesize($destPath))));

        return Command::SUCCESS;
    }

    /* -----------------------------------------------------------------
     * Portable JSON export — MySQL import via $wpdb
     * ----------------------------------------------------------------- */

    /**
     * @return array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>}|null
     */
    private function decodePortableExport(string $path): ?array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (
            !is_array($decoded)
            || !isset($decoded['books'], $decoded['chapters'], $decoded['verses'], $decoded['sections'], $decoded['notes'])
            || !is_array($decoded['books'])
            || !is_array($decoded['chapters'])
            || !is_array($decoded['verses'])
            || !is_array($decoded['sections'])
            || !is_array($decoded['notes'])
        ) {
            return null;
        }

        /** @var array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>} $decoded */
        return $decoded;
    }

    /**
     * @param array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>} $data
     */
    private function installMysql(array $data, SymfonyStyle $io): int
    {
        if (!$this->bootWordPress($io)) {
            return Command::FAILURE;
        }

        MysqlBibleInstaller::install($data);

        $io->success(sprintf(
            'Imported %d books, %d chapters, %d verses, %d sections, %d notes into MySQL storage.',
            count($data['books']),
            count($data['chapters']),
            count($data['verses']),
            count($data['sections']),
            count($data['notes'])
        ));

        return Command::SUCCESS;
    }

    /* -----------------------------------------------------------------
     * Shared
     * ----------------------------------------------------------------- */

    private function bootWordPress(SymfonyStyle $io): bool
    {
        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory.');
            return false;
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        return true;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
