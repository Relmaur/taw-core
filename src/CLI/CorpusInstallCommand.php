<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Corpus\Storage;
use TAW\Core\Storage\ProtectedSqlite;

/**
 * `bin/taw corpus:install <path> <filename>`
 *
 * Installs a developer-curated reference-corpus `.sqlite` file (e.g. the
 * Straubinger Bible) into the protected `taw-private/corpus` directory
 * (see {@see \TAW\Core\Corpus\Storage}) under a fixed, versionless
 * filename — reader code never has to care which build is installed;
 * that's tracked inside the file's own meta table instead.
 *
 * Deliberately a CLI install step, not a wp-admin upload form: this is a
 * curated dataset a developer places once per environment, not something
 * an end-user is expected to swap through a web UI the way an admin
 * uploads a RAG knowledge base (see
 * {@see \TAW\Core\Rag\KnowledgeBase\KnowledgeBaseAdminScreen}). An upload
 * screen can be added later if that assumption turns out wrong.
 *
 * Copies rather than moves the source file, so re-running this command
 * against the same source (e.g. after re-downloading it) is always safe.
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
            ->setDescription('Install a reference-corpus .sqlite file into the protected corpus directory')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the source .sqlite file')
            ->addArgument('filename', InputArgument::REQUIRED, 'Destination filename, e.g. bible-straubinger.sqlite');
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

        if (!preg_match('/^[A-Za-z0-9_.-]+\.sqlite$/', $filename)) {
            $io->error("'{$filename}' must be a bare .sqlite filename (letters, digits, '-', '_', '.' only, no path segments).");
            return Command::FAILURE;
        }

        if (!ProtectedSqlite::looksLikeSqliteFile($sourcePath)) {
            $io->error("'{$sourcePath}' does not look like a SQLite database file.");
            return Command::FAILURE;
        }

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory.');
            return Command::FAILURE;
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        Storage::ensureProtectedDir(Storage::dir());
        $destPath = Storage::dbPath($filename);

        if (!copy($sourcePath, $destPath)) {
            $io->error("Failed to copy the file to '{$destPath}'.");
            return Command::FAILURE;
        }

        $io->success(sprintf('Installed %s (%s).', $filename, self::humanSize((int) filesize($destPath))));

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
