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
 * Export a block as a portable ZIP archive.
 *
 * PORTABILITY: Receives $themeDir via injection (see MakeBlockCommand).
 */
class ExportBlockCommand extends Command
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
            ->setName('export:block')
            ->setDescription('Export a block as a portable ZIP')
            ->addArgument('name', InputArgument::REQUIRED, 'Block name to export')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        $blockDir = $this->themeDir . '/Blocks/' . $name;

        if (!is_dir($blockDir)) {
            $io->error("Block '{$name}' not found at {$blockDir}");
            return Command::FAILURE;
        }

        $outputDir = rtrim($input->getOption('output'), '/');
        $zipName   = "taw-block-{$name}.zip";
        $zipPath   = $outputDir . '/' . $zipName;

        if (!class_exists(\ZipArchive::class)) {
            $io->error([
                'The PHP zip extension is not installed.',
                'Install it: sudo apt install php-zip (Linux) or brew install php (macOS).',
            ]);
            return Command::FAILURE;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $io->error("Could not create ZIP at {$zipPath}");
            return Command::FAILURE;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($blockDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $fileList = [];
        foreach ($files as $file) {
            $relativePath = $name . '/' . substr($file->getRealPath(), strlen($blockDir) + 1);
            $zip->addFile($file->getRealPath(), $relativePath);
            $fileList[] = substr($file->getRealPath(), strlen($blockDir) + 1);
        }

        $manifest = [
            'name'        => $name,
            'exported_at' => date('c'),
            'taw_version' => '1.0.0',
            'php_version'  => PHP_VERSION,
            'files'       => $fileList,
        ];

        $zip->addFromString(
            $name . '/block.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $zip->close();

        $sizeBytes = filesize($zipPath);
        $sizeFormatted = $sizeBytes > 1024
            ? round($sizeBytes / 1024, 1) . ' KB'
            : $sizeBytes . ' bytes';

        $io->success("Exported '{$name}' → {$zipPath} ({$sizeFormatted})");
        $io->table(
            ['File', 'Included'],
            array_map(fn($f) => [$f, '✓'], $fileList)
        );

        return Command::SUCCESS;
    }
}
