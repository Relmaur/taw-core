<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Content\Importer;

/**
 * `taw content:journals [--json]`: every import this site received (an
 * import, or a publish from taw-fleet), newest first, with what each changed
 * and whether it was undone. `content:import --undo=<file>` reverses one.
 */
class ContentJournalsCommand extends Command
{
    public function __construct(private readonly string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('content:journals')
            ->setDescription('List the imports and publishes this site received, newest first (content:import --undo=<file> reverses one)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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

        $journals = Importer::journals();
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['journals' => $journals], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }
        if ($journals === []) {
            $io->text('No imports yet.');
            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($journals as $j) {
            $rows[] = [$j['file'], $j['created_at'], (string) ($j['source'] ?? ''),
                sprintf('%d changed, %d created, %d deleted', $j['counts']['updated'], $j['counts']['created'], $j['counts']['deleted']),
                $j['undone'] ? 'undone' : ''];
        }
        $io->table(['Journal', 'When', 'From', 'What', ''], $rows);
        $io->text('Undo one: <info>taw content:import --undo=<journal></info> (preview), then with <info>--yes</info>.');

        return Command::SUCCESS;
    }
}
