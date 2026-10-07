<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Corpus\CanonLaw\CanonLawEditions;
use TAW\Core\Corpus\CanonLaw\MysqlCanonLawInstaller;
use TAW\Core\Corpus\Storage;
use TAW\Core\Storage\ProtectedSqlite;

/**
 * `bin/taw canon-law:install <edition> <path>`
 *
 * Installs one Code of Canon Law edition (slug registered in
 * {@see CanonLawEditions}) from either source format, mirroring
 * {@see CatechismInstallCommand}:
 *
 *  - a raw `.sqlite` export — copied into the protected
 *    `taw-private/corpus` directory under the edition's registered
 *    filename (needs `pdo_sqlite` here);
 *  - a portable JSON export ({@see CanonLawExportCommand}) — imported into
 *    MySQL via {@see MysqlCanonLawInstaller} (no `pdo_sqlite` needed).
 *
 * Before installing either, it checks the export is what it claims to be:
 * its `meta.surface` must be `canon_law` (so a Bible or Catechism export is
 * refused), and a `<file>.sha256` sidecar next to a `.sqlite` source — the
 * upstream proofreading app writes one with every export — must match the
 * file's digest. A missing sidecar is a warning, not a failure.
 */
class CanonLawInstallCommand extends Command
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
            ->setName('canon-law:install')
            ->setDescription('Install a canon law corpus (.sqlite file or a portable JSON export) for one edition')
            ->addArgument('edition', InputArgument::REQUIRED, 'Edition slug registered in CanonLawEditions, e.g. cic-1983')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the source .sqlite file or JSON export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $edition = (string) $input->getArgument('edition');
        $sourcePath = (string) $input->getArgument('path');

        if (!CanonLawEditions::exists($edition)) {
            $known = implode(', ', array_column(CanonLawEditions::all(), 'slug'));
            $io->error("Unknown canon law edition '{$edition}'. Registered editions: {$known}.");
            return Command::FAILURE;
        }

        if (!is_file($sourcePath)) {
            $io->error("No file found at '{$sourcePath}'.");
            return Command::FAILURE;
        }

        if (ProtectedSqlite::looksLikeSqliteFile($sourcePath)) {
            return $this->installSqlite($edition, $sourcePath, $io);
        }

        $portable = self::decodePortableExport($sourcePath);
        if ($portable !== null) {
            return $this->installMysql($edition, $portable, $io);
        }

        $io->error("'{$sourcePath}' is neither a SQLite database file nor a recognized portable canon law export (expected JSON with divisions/canons arrays).");
        return Command::FAILURE;
    }

    /**
     * The `<file>.sha256` sidecar's digest check: null when it matches,
     * a message when it doesn't. `sha256sum` format (`<hex>  <name>`) or a
     * bare hex digest are both accepted.
     */
    public static function checksumError(string $path): ?string
    {
        $sidecar = $path . '.sha256';
        if (!is_file($sidecar)) {
            $sidecar = preg_replace('/\.sqlite$/', '.sha256', $path) ?? '';
            if ($sidecar === '' || !is_file($sidecar)) {
                return null;
            }
        }

        $expected = strtolower(strtok(trim((string) file_get_contents($sidecar)), " \t") ?: '');
        $actual = hash_file('sha256', $path);

        return hash_equals($expected, (string) $actual)
            ? null
            : "Checksum mismatch: {$sidecar} says {$expected}, the file is {$actual}. Re-download the export.";
    }

    /**
     * Null when the export's surface is canon law (or it carries no meta at
     * all — an export from before the meta table existed), otherwise a message.
     *
     * @param array<string, string|null> $meta
     */
    public static function surfaceError(array $meta): ?string
    {
        $surface = $meta['surface'] ?? null;
        if ($surface === null || $surface === CanonLawEditions::SURFACE) {
            return null;
        }

        return "This export's surface is '{$surface}', not '" . CanonLawEditions::SURFACE . "' — it isn't a Code of Canon Law export.";
    }

    private function installSqlite(string $edition, string $sourcePath, SymfonyStyle $io): int
    {
        if (!ProtectedSqlite::isAvailable()) {
            $io->error(
                "This host has no working pdo_sqlite driver, so a raw .sqlite corpus can't be installed here. " .
                "Run `bin/taw canon-law:export {$sourcePath} <output.json>` on a machine that does, then " .
                "`bin/taw canon-law:install {$edition} <output.json>` on this host instead."
            );
            return Command::FAILURE;
        }

        $checksum = self::checksumError($sourcePath);
        if ($checksum !== null) {
            $io->error($checksum);
            return Command::FAILURE;
        }

        $pdo = new \PDO('sqlite:' . $sourcePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $meta = CanonLawExportCommand::readMeta($pdo);
        $surface = self::surfaceError($meta);
        if ($surface !== null) {
            $io->error($surface);
            return Command::FAILURE;
        }
        unset($pdo);

        if (!$this->bootWordPress($io)) {
            return Command::FAILURE;
        }

        Storage::ensureProtectedDir(Storage::dir());
        $destPath = Storage::dbPath((string) CanonLawEditions::filename($edition));

        if (!copy($sourcePath, $destPath)) {
            $io->error("Failed to copy the file to '{$destPath}'.");
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Installed %s as SQLite storage (%s, release channel: %s).',
            basename($destPath),
            $meta['slug'] ?? 'no meta',
            $meta['release_channel'] ?? 'unknown'
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{divisions: list<array<string, mixed>>, canons: list<array<string, mixed>>, meta: array<string, string|null>}|null
     */
    public static function decodePortableExport(string $path): ?array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (
            !is_array($decoded)
            || !isset($decoded['divisions'], $decoded['canons'])
            || !is_array($decoded['divisions'])
            || !is_array($decoded['canons'])
        ) {
            return null;
        }

        $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];

        /** @var array{divisions: list<array<string, mixed>>, canons: list<array<string, mixed>>, meta: array<string, string|null>} */
        return ['divisions' => $decoded['divisions'], 'canons' => $decoded['canons'], 'meta' => $meta];
    }

    /**
     * @param array{divisions: list<array<string, mixed>>, canons: list<array<string, mixed>>, meta: array<string, string|null>} $data
     */
    private function installMysql(string $edition, array $data, SymfonyStyle $io): int
    {
        $surface = self::surfaceError($data['meta']);
        if ($surface !== null) {
            $io->error($surface);
            return Command::FAILURE;
        }

        if (!$this->bootWordPress($io)) {
            return Command::FAILURE;
        }

        try {
            $counts = MysqlCanonLawInstaller::install($edition, $data);
        } catch (\Throwable $e) {
            $io->error('MySQL import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %d divisions, %d canons into MySQL storage (edition: %s, release channel: %s).',
            $counts['divisions'],
            $counts['canons'],
            $edition,
            $data['meta']['release_channel'] ?? 'unknown'
        ));

        return Command::SUCCESS;
    }

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
}
