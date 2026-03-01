<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import a block from a TAW block ZIP archive.
 *
 * Reads the block.json manifest, validates the package,
 * and extracts into Blocks/.
 */
class ImportBlockCommand extends Command
{
    private string $themeDir;

    public function __construct()
    {
        parent::__construct();
        $this->themeDir = dirname(__DIR__, 2);
    }

    protected function configure(): void
    {
        $this
            ->setName('import:block')
            ->setDescription('Import a block from a TAW block ZIP')
            ->setHelp(<<<'HELP'
                Extracts a block package (created by <info>export:block</info>)
                into your theme's Blocks/ directory.

                The ZIP must contain a block.json manifest.

                Example:
                  <info>php bin/taw import:block taw-block-Hero.zip</info>
                  <info>php bin/taw import:block taw-block-Hero.zip --force</info>
                HELP)
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to the block ZIP file'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite if block already exists'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $zipPath = $input->getArgument('path');
        $force   = $input->getOption('force');

        // --- Validate the ZIP file ---
        if (!file_exists($zipPath)) {
            $io->error("File not found: {$zipPath}");
            return Command::FAILURE;
        }

        if (!class_exists(\ZipArchive::class)) {
            $io->error('The PHP zip extension is not installed.');
            return Command::FAILURE;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $io->error("Could not open ZIP: {$zipPath}");
            return Command::FAILURE;
        }

        // --- Read the manifest ---
        // The first directory in the ZIP is the block name.
        $firstEntry = $zip->getNameIndex(0);
        $name       = explode('/', $firstEntry)[0];

        // Try to read the manifest
        $manifestJson = $zip->getFromName($name . '/block.json');
        $manifest     = $manifestJson ? json_decode($manifestJson, true) : null;

        if ($manifest) {
            $io->section('Block Manifest');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Name', $manifest['name'] ?? $name],
                    ['Exported', $manifest['exported_at'] ?? 'Unknown'],
                    ['TAW Version', $manifest['taw_version'] ?? 'Unknown'],
                    ['Files', count($manifest['files'] ?? [])],
                ]
            );
        }

        // --- Check for existing block ---
        $targetDir = $this->themeDir . '/Blocks/' . $name;

        if (is_dir($targetDir) && !$force) {
            // Interactive confirmation — ask the user what to do.
            // This is another Symfony Console superpower.
            if (!$io->confirm("Block '{$name}' already exists. Overwrite?", false)) {
                $io->warning('Import cancelled.');
                $zip->close();
                return Command::SUCCESS;
            }

            // User said yes — remove existing block
            $this->removeDirectory($targetDir);
        }

        // --- Extract ---
        // extractTo() extracts the entire ZIP maintaining folder structure.
        // Since the ZIP contains {Name}/... and we extract to Blocks/,
        // the result is Blocks/{Name}/... — exactly right.
        $blocksDir = $this->themeDir . '/Blocks';
        $zip->extractTo($blocksDir);
        $zip->close();

        // Remove the block.json manifest from the extracted files
        // (it's metadata for the CLI tool, not part of the block itself)
        $manifestFile = $targetDir . '/block.json';
        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }

        $io->success("Block '{$name}' imported!");

        $io->section('Next Steps');
        $io->listing([
            'Run <info>composer dump-autoload</info> to register the class',
            'Review the block at <info>Blocks/' . $name . '/</info>',
            'Check for any dependencies specific to the source project',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Recursively remove a directory.
     *
     * PHP doesn't have a built-in rmdir-recursive, so we
     * iterate depth-first and delete files before directories.
     */
    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
