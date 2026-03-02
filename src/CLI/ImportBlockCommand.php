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
 * and extracts into Blocks/ (respecting group paths).
 */
class ImportBlockCommand extends Command
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
            ->setName('import:block')
            ->setDescription('Import a block from a TAW block ZIP')
            ->setHelp(<<<'HELP'
                Extracts a block package (created by <info>export:block</info>)
                into your theme's Blocks/ directory.

                The ZIP must contain a block.json manifest. If the manifest
                includes a group path, the block is placed there automatically.
                Use <info>--group</info> to override or specify a different location.

                Examples:
                  <info>php bin/taw import:block taw-block-Hero.zip</info>
                  <info>php bin/taw import:block taw-block-Hero.zip --group=sections</info>
                  <info>php bin/taw import:block taw-block-Hero.zip --force</info>
                HELP)
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to the block ZIP file'
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_REQUIRED,
                'Group subfolder to import into — overrides the manifest group (e.g. sections, ui/cards)'
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
        // ZIP entries are stored as {blockName}/file, so the first segment is always the block name.
        $firstEntry = $zip->getNameIndex(0);
        $name       = explode('/', $firstEntry)[0];

        $manifestJson = $zip->getFromName($name . '/block.json');
        $manifest     = $manifestJson ? json_decode($manifestJson, true) : null;

        if ($manifest) {
            $io->section('Block Manifest');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Name',         $manifest['name'] ?? $name],
                    ['Group',        $manifest['group'] ?: '(none)'],
                    ['Exported',     $manifest['exported_at'] ?? 'Unknown'],
                    ['TAW Version',  $manifest['taw_version'] ?? 'Unknown'],
                    ['Files',        count($manifest['files'] ?? [])],
                ]
            );
        }

        // --group flag takes precedence over the manifest group
        $group = $input->getOption('group') ?? ($manifest['group'] ?? '');
        $group = trim((string) $group, '/');

        // --- Resolve target directory ---
        $blocksBase = $this->themeDir . '/Blocks';
        $extractTo  = $group ? $blocksBase . '/' . $group : $blocksBase;
        $targetDir  = $extractTo . '/' . $name;
        $displayPath = ($group ? $group . '/' : '') . $name;

        // --- Check for existing block ---
        if (is_dir($targetDir) && !$force) {
            if (!$io->confirm("Block '{$name}' already exists at Blocks/{$displayPath}. Overwrite?", false)) {
                $io->warning('Import cancelled.');
                $zip->close();
                return Command::SUCCESS;
            }

            $this->removeDirectory($targetDir);
        }

        // --- Create group directory if needed ---
        if (!is_dir($extractTo)) {
            mkdir($extractTo, 0755, true);
        }

        // Extract — ZIP stores {blockName}/file, extracting to $extractTo gives
        // $extractTo/{blockName}/file, which is exactly $targetDir.
        $zip->extractTo($extractTo);
        $zip->close();

        // Remove the block.json manifest (CLI metadata, not part of the block)
        $manifestFile = $targetDir . '/block.json';
        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }

        $io->success("Block '{$name}' imported to Blocks/{$displayPath}!");

        $io->section('Next Steps');
        $io->listing([
            'Run <info>composer dump-autoload</info> to register the class',
            'Review the block at <info>Blocks/' . $displayPath . '/</info>',
            'Check for any dependencies specific to the source project',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Recursively remove a directory.
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
