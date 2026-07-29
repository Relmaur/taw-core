<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vendors the Lucide icon set (https://github.com/lucide-icons/lucide) into
 * this package so TAW\Core\Icons\Lucide never needs a network call at
 * runtime — the wp-admin icon picker searches a local index and reads local
 * SVG files.
 *
 * Re-run this whenever Lucide ships new icons. Does NOT touch WordPress or
 * any consuming theme — it only writes into this package's own resources/
 * directory, same as SyncCommand's clone/cleanup technique but without the
 * tier1/tier2 diffing (there's nothing to diff against; every run is a full
 * re-vendor).
 */
class IconsSyncCommand extends Command
{
    private const REPO_URL = 'https://github.com/lucide-icons/lucide.git';

    protected function configure(): void
    {
        $this
            ->setName('icons:sync')
            ->setDescription('Vendor the latest Lucide icon set into resources/icons/lucide/')
            ->setHelp(
                'Shallow-clones lucide-icons/lucide (sparse-checkout of icons/ only), copies ' .
                'every icon SVG into resources/icons/lucide/, and rebuilds ' .
                'resources/icons/lucide-index.json (name + searchable tags/categories/aliases) ' .
                'from each icon\'s metadata file. Requires git and network access.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lucide Icon Sync');

        $packageRoot = dirname(__DIR__, 2);
        $iconsDir = $packageRoot . '/resources/icons/lucide';
        $indexPath = $packageRoot . '/resources/icons/lucide-index.json';

        $clone = $this->shallowCloneIcons();

        if ($clone === null) {
            $io->error('Could not clone ' . self::REPO_URL . ' — check network access and that git is installed.');
            return Command::FAILURE;
        }

        try {
            $sourceDir = $clone . '/icons';

            if (!is_dir($sourceDir)) {
                $io->error('Clone succeeded but icons/ was not found in the repository.');
                return Command::FAILURE;
            }

            @mkdir($iconsDir, 0755, true);

            $svgFiles = glob($sourceDir . '/*.svg') ?: [];
            $index = [];

            foreach ($svgFiles as $svgPath) {
                $name = basename($svgPath, '.svg');

                copy($svgPath, $iconsDir . '/' . $name . '.svg');

                $index[] = [
                    'name' => $name,
                    'keywords' => $this->readKeywords($sourceDir . '/' . $name . '.json'),
                ];
            }

            usort($index, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

            file_put_contents(
                $indexPath,
                (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $io->success(sprintf('Vendored %d Lucide icons into %s', count($index), $iconsDir));

            return Command::SUCCESS;
        } finally {
            $this->removeDirectory($clone);
        }
    }

    /**
     * Reads an icon's Lucide metadata file and flattens tags, categories,
     * and alias names into one searchable keyword list.
     *
     * @return string[]
     */
    private function readKeywords(string $jsonPath): array
    {
        if (!file_exists($jsonPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($jsonPath), true);

        if (!is_array($decoded)) {
            return [];
        }

        $keywords = array_merge(
            $decoded['tags'] ?? [],
            $decoded['categories'] ?? [],
            array_map(
                static fn(array $alias): string => (string) ($alias['name'] ?? ''),
                $decoded['aliases'] ?? []
            )
        );

        $keywords = array_values(array_unique(array_filter($keywords)));
        sort($keywords);

        return $keywords;
    }

    private function shallowCloneIcons(): ?string
    {
        $dest = sys_get_temp_dir() . '/taw-icons-sync-' . bin2hex(random_bytes(6));

        exec(
            'git clone --quiet --depth=1 --filter=blob:none --sparse '
                . escapeshellarg(self::REPO_URL) . ' ' . escapeshellarg($dest)
                . ' && cd ' . escapeshellarg($dest)
                . ' && git sparse-checkout set icons 2>&1',
            $outputLines,
            $exitCode
        );

        return $exitCode === 0 && is_dir($dest) ? $dest : null;
    }

    private function removeDirectory(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
