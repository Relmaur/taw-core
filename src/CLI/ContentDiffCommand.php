<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Content\ChangeSet;

/**
 * `bin/taw content:diff <a.json> <b.json> [--out=changes.json]`
 *
 * Diffs two snapshots (`content:export` output) into a change-set — the
 * `{taw_changeset, operations: [...]}` shape `content:import` also accepts.
 * Pure file-to-file transform; does not boot WordPress.
 */
class ContentDiffCommand extends Command
{
    /**
     * @param string $themeDir Unused — accepted for parity with the other
     *                          CLI commands' constructor signature (bin/taw
     *                          injects it uniformly). content:diff is a pure
     *                          file-to-file transform and never boots WordPress.
     */
    public function __construct(string $themeDir)
    {
        unset($themeDir);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('content:diff')
            ->setDescription('Diff two content snapshots into a change-set for content:import')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/taw content:diff before.json after.json</info>
                  <info>php bin/taw content:diff before.json after.json --out=.taw/changes.json</info>
                HELP)
            ->addArgument('a', InputArgument::REQUIRED, 'The "from" snapshot')
            ->addArgument('b', InputArgument::REQUIRED, 'The "to" snapshot')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write the change-set here instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $a = $this->readJson((string) $input->getArgument('a'), $io);
        $b = $this->readJson((string) $input->getArgument('b'), $io);
        if ($a === null || $b === null) {
            return Command::FAILURE;
        }

        $changeSet = ChangeSet::between($a, $b);
        $encoded = (string) json_encode($changeSet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $out = $input->getOption('out');
        if (is_string($out) && $out !== '') {
            $dir = dirname($out);
            if ($dir !== '.' && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($out, $encoded);
            $io->success(sprintf('Wrote %s (%d operations)', $out, count($changeSet['operations'])));
            return Command::SUCCESS;
        }

        $output->writeln($encoded);
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path, SymfonyStyle $io): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            $io->error("Cannot read file: {$path}");
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $io->error("Not valid JSON: {$path}");
            return null;
        }
        return $data;
    }
}
