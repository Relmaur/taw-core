<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TAW\Update\ConfigFiles;

/**
 * `bin/taw configs:sync`: turns a classic theme's unedited copies of
 * vite.config.js and phpstan.neon into short files that load taw/core's base
 * (which then updates with it). Edited files are left alone, with the by-hand
 * steps. See ConfigFiles.
 */
final class ConfigsSyncCommand extends Command
{
    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('configs:sync')
            ->setDescription('Point vite.config.js and phpstan.neon at taw/core\'s base (check only; --apply writes)')
            ->setHelp(
                "The base of a classic theme's Vite and PHPStan configs ships with taw/core and updates with it;\n" .
                "the theme keeps a short file that loads it and adds the site's own settings.\n\n" .
                "Only a file identical to a version the scaffold once shipped is replaced (an unedited copy).\n" .
                "A file the site edited is left alone and listed with what to do by hand.\n" .
                "The previous text stays in git history (git log -p -- vite.config.js).\n\n" .
                "  <info>php bin/taw configs:sync</info>           what would change\n" .
                "  <info>php bin/taw configs:sync --apply</info>   convert the unedited copies\n" .
                "  <info>php bin/taw configs:sync --json</info>    for scripts"
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configs = new ConfigFiles($this->themeDir);
        $plan = $configs->plan();
        $written = $input->getOption('apply') ? $configs->apply($plan) : [];

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($plan + ['applied' => $written, 'by_hand' => array_intersect_key(ConfigFiles::BY_HAND, array_flip($plan['custom']))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        foreach ($plan['convert'] as $path) {
            $output->writeln(($written !== [] ? '  <info>✓ converted</info> ' : '  <comment>↻ to convert</comment> ') . $path . ' <comment>(an unedited copy of the scaffold\'s)</comment>');
        }
        foreach ($plan['current'] as $path) {
            $output->writeln('  <info>✓</info> ' . $path . ' (loads taw/core\'s base)');
        }
        foreach ($plan['custom'] as $path) {
            $output->writeln('  <comment>! ' . $path . '</comment> has this site\'s own changes, so it is left as is. By hand: ' . ConfigFiles::BY_HAND[$path]);
        }
        if ($written === [] && $plan['convert'] !== []) {
            $output->writeln('');
            $output->writeln('Run <info>php bin/taw configs:sync --apply</info>, then <info>npm run build</info> and <info>composer run phpstan</info>, and commit.');
        }

        return Command::SUCCESS;
    }
}
