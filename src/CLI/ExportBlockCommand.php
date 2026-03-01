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
 * PORTABILITY: Receives $themeDir via injection (see MakeBlockCommand).
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
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the block ZIP file')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite if block already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $zipPath = $input->getArgument('path');
        $force   = $input->getOption('force');

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

        $firstEntry = $zip->getNameIndex(0);
        $name       = explode('/', $firstEntry)[0];

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

        $targetDir = $this->themeDir . '/Blocks/' . $name;

        if (is_dir($targetDir) && !$force) {
            if (!$io->confirm("Block '{$name}' already exists. Overwrite?", false)) {
                $io->warning('Import cancelled.');
                $zip->close();
                return Command::SUCCESS;
            }
            $this->removeDirectory($targetDir);
        }

        $blocksDir = $this->themeDir . '/Blocks';
        $zip->extractTo($blocksDir);
        $zip->close();

        $manifestFile = $targetDir . '/block.json';
        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }

        $io->success("Block '{$name}' imported!");

        $io->section('Next Steps');
        $io->listing([
            'Run <info>composer dump-autoload</info> to register the class',
            'Review the block at <info>Blocks/' . $name . '/</info>',
        ]);

        return Command::SUCCESS;
    }

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
