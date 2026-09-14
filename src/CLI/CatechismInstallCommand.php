<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Corpus\Catechism\CatechismEditions;
use TAW\Core\Corpus\Catechism\MysqlCatechismInstaller;
use TAW\Core\Corpus\Storage;
use TAW\Core\Storage\ProtectedSqlite;

/**
 * `bin/taw catechism:install <edition> <path> [filename]`
 *
 * Installs a developer-curated catechism corpus for one edition (see
 * {@see CatechismEditions} for the registry of valid slugs) from either of
 * two source formats, exactly mirroring {@see CorpusInstallCommand}
 * (Bible's equivalent — read that class's docblock for the full source-
 * format rationale):
 *
 *  - A raw `.sqlite` file — copied into the protected `taw-private/corpus`
 *    directory under `filename` (defaults to the edition's registered
 *    filename via {@see CatechismEditions::filename()} if omitted).
 *    Requires a working `pdo_sqlite` driver *on this machine*.
 *  - A portable JSON export (see {@see CatechismExportCommand}) — imported
 *    into MySQL tables via `$wpdb` ({@see MysqlCatechismInstaller}), which
 *    needs no `pdo_sqlite` on this machine at all. `filename` is unused
 *    for this path — MySQL tables have fixed names, one shared table-set
 *    across every edition (see {@see \TAW\Core\Corpus\Catechism\
 *    MysqlCatechismSchema}'s docblock).
 *
 * `$edition` is required on both paths (unlike Bible, which has exactly
 * one corpus) — every install is explicitly "install *this* catechism
 * edition," never an ambient single corpus.
 */
class CatechismInstallCommand extends Command
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
            ->setName('catechism:install')
            ->setDescription('Install a catechism corpus (.sqlite file or a portable JSON export) for one edition')
            ->addArgument('edition', InputArgument::REQUIRED, 'Edition slug registered in CatechismEditions, e.g. pius-x')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the source .sqlite file or JSON export')
            ->addArgument('filename', InputArgument::OPTIONAL, 'Destination filename for a .sqlite source (defaults to the edition\'s registered filename; unused for a JSON source)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $edition = (string) $input->getArgument('edition');
        $sourcePath = (string) $input->getArgument('path');
        $filenameArg = $input->getArgument('filename');

        if (!CatechismEditions::exists($edition)) {
            $known = implode(', ', array_column(CatechismEditions::all(), 'slug'));
            $io->error("Unknown catechism edition '{$edition}'. Registered editions: {$known}.");
            return Command::FAILURE;
        }

        if (!is_file($sourcePath)) {
            $io->error("No file found at '{$sourcePath}'.");
            return Command::FAILURE;
        }

        if (ProtectedSqlite::looksLikeSqliteFile($sourcePath)) {
            $filename = $filenameArg !== null ? (string) $filenameArg : (string) CatechismEditions::filename($edition);
            return $this->installSqlite($sourcePath, $filename, $io);
        }

        $portable = $this->decodePortableExport($sourcePath);
        if ($portable !== null) {
            return $this->installMysql($edition, $portable, $io);
        }

        $io->error("'{$sourcePath}' is neither a SQLite database file nor a recognized portable corpus export (expected JSON with part/section/chapter/paragraph arrays).");
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
                "Run `bin/taw catechism:export {$sourcePath} <output.json>` on a machine that does (typically a " .
                "developer's local machine), then `bin/taw catechism:install <edition> <output.json>` on this host instead."
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
     * @return array{parts: list<array<string, mixed>>, sections: list<array<string, mixed>>, chapters: list<array<string, mixed>>, paragraphs: list<array<string, mixed>>}|null
     */
    private function decodePortableExport(string $path): ?array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (
            !is_array($decoded)
            || !isset($decoded['parts'], $decoded['sections'], $decoded['chapters'], $decoded['paragraphs'])
            || !is_array($decoded['parts'])
            || !is_array($decoded['sections'])
            || !is_array($decoded['chapters'])
            || !is_array($decoded['paragraphs'])
        ) {
            return null;
        }

        /** @var array{parts: list<array<string, mixed>>, sections: list<array<string, mixed>>, chapters: list<array<string, mixed>>, paragraphs: list<array<string, mixed>>} $decoded */
        return $decoded;
    }

    /**
     * @param array{parts: list<array<string, mixed>>, sections: list<array<string, mixed>>, chapters: list<array<string, mixed>>, paragraphs: list<array<string, mixed>>} $data
     */
    private function installMysql(string $edition, array $data, SymfonyStyle $io): int
    {
        if (!$this->bootWordPress($io)) {
            return Command::FAILURE;
        }

        try {
            $counts = MysqlCatechismInstaller::install($edition, $data);
        } catch (\Throwable $e) {
            $io->error('MySQL import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %d parts, %d sections, %d chapters, %d paragraphs into MySQL storage (edition: %s).',
            $counts['parts'],
            $counts['sections'],
            $counts['chapters'],
            $counts['paragraphs'],
            $edition
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
