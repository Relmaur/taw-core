<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Rag\Usage\UsageMeter;
use TAW\Core\Rest\RagChatEndpoint;

/**
 * `bin/taw rag:usage [--days=30]` — the chatbot's metered LLM spend against
 * its budgets, per day; the CLI twin of TAW Chatbot → Usage, handy over SSH
 * and for fleet tooling.
 *
 * `--uninstall` is the usage ledger's rollback path: it drops the
 * `{prefix}taw_rag_usage` table and its options, after a confirmation
 * (skippable with `--yes`), since the spend history can't be recovered.
 */
class RagUsageCommand extends Command
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
            ->setName('rag:usage')
            ->setDescription('Show the chatbot\'s metered LLM spend against its budgets')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of history to list', '30')
            ->addOption('uninstall', null, InputOption::VALUE_NONE, 'Drop the usage ledger table and its options')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the --uninstall confirmation');
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

        if ($input->getOption('uninstall')) {
            return $this->uninstall($input, $io);
        }

        $days = max(1, min(366, (int) $input->getOption('days')));

        try {
            $status = UsageMeter::status();
            $history = UsageMeter::history($days);
        } catch (\Throwable $e) {
            $io->error('Could not read the usage ledger: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Today' => UsageMeter::formatUsd($status['day_spent']) . ' of ' . UsageMeter::formatUsd($status['day_limit'])],
            ['This month' => UsageMeter::formatUsd($status['month_spent']) . ' of ' . UsageMeter::formatUsd($status['month_limit'])],
            ['Worst case per message' => UsageMeter::formatUsd(RagChatEndpoint::currentReserveMicros())]
        );

        if ($history === []) {
            $io->text("No metered calls in the last {$days} day(s).");
            return Command::SUCCESS;
        }

        $io->table(
            ['Day', 'Calls', 'Input tokens', 'Output tokens', 'Cost'],
            array_map(static fn (array $row): array => [
                $row['day'],
                number_format($row['requests']),
                number_format($row['prompt_tokens']),
                number_format($row['completion_tokens']),
                UsageMeter::formatUsd($row['cost_micros']),
            ], $history)
        );

        return Command::SUCCESS;
    }

    private function uninstall(InputInterface $input, SymfonyStyle $io): int
    {
        if (!$input->getOption('yes') && !$io->confirm('Drop the usage ledger? Its spend history cannot be recovered.', false)) {
            $io->text('Nothing changed.');
            return Command::SUCCESS;
        }

        try {
            UsageMeter::uninstall();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Usage ledger removed. If the chatbot is still enabled, the next metered call starts a new, empty one.');

        return Command::SUCCESS;
    }
}
