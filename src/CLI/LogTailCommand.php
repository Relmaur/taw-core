<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Log\Level;
use TAW\Core\Log\LogReader;

/**
 * The human-facing half of the Logger suite (`TAW\Core\Log`) — reads back
 * whatever `JsonlFileSink` wrote to `wp-content/taw-logs/taw.log.jsonl`,
 * same file the `taw-hub-companion` `/logs` route serves to the Hub.
 *
 *   php bin/taw log:tail                        # last 50 entries, readable
 *   php bin/taw log:tail --limit=200
 *   php bin/taw log:tail --level=error
 *   php bin/taw log:tail --code=form             # prefix match on `code`
 *   php bin/taw log:tail --since=2026-09-01T00:00:00+00:00
 *   php bin/taw log:tail --json                  # raw entries, for piping
 */
class LogTailCommand extends Command
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
            ->setName('log:tail')
            ->setDescription('Show the most recent structured log entries (TAW\\Core\\Log)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max entries to show', '50')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Filter: debug|info|notice|warning|error|critical')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Filter: code prefix, e.g. "form" or "mail.emailit"')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter: ISO-8601 timestamp, entries at/after it')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print raw JSON entries instead of a formatted table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $level = $input->getOption('level');
        if (is_string($level) && $level !== '' && !Level::isValid($level)) {
            $io->error(sprintf('Unknown level "%s". Valid: %s', $level, implode(', ', Level::ALL)));

            return Command::FAILURE;
        }

        $dir = self::resolveLogDir($this->themeDir);
        if ($dir === null) {
            $io->error('Could not locate wp-content — run this from within a TAW theme checkout.');

            return Command::FAILURE;
        }

        $limitOption = $input->getOption('limit');
        $limit = is_string($limitOption) || is_int($limitOption) ? max(1, (int) $limitOption) : 50;

        $codeOption  = $input->getOption('code');
        $sinceOption = $input->getOption('since');

        $entries = (new LogReader($dir))->tail(
            $limit,
            is_string($level) && $level !== '' ? $level : null,
            is_string($sinceOption) && $sinceOption !== '' ? $sinceOption : null,
            is_string($codeOption) && $codeOption !== '' ? $codeOption : null,
        );

        if ($entries === []) {
            $io->writeln('<comment>No log entries match.</comment>');

            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $io->writeln((string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        foreach ($entries as $entry) {
            $this->printEntry($io, $entry);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function printEntry(SymfonyStyle $io, array $entry): void
    {
        $level = strtoupper((string) ($entry['level'] ?? ''));
        $color = match ($entry['level'] ?? '') {
            Level::CRITICAL, Level::ERROR => 'red',
            Level::WARNING => 'yellow',
            Level::DEBUG => 'gray',
            default => 'cyan',
        };

        $io->writeln(sprintf(
            '<fg=gray>%s</> <fg=%s>[%s]</> <comment>%s</comment> %s',
            (string) ($entry['ts'] ?? ''),
            $color,
            $level,
            (string) ($entry['code'] ?? ''),
            (string) ($entry['message'] ?? ''),
        ));

        $context = $entry['context'] ?? [];
        if (is_array($context) && $context !== []) {
            $io->writeln('  <fg=gray>' . json_encode($context, JSON_UNESCAPED_SLASHES) . '</>');
        }
    }

    /**
     * `wp-content/taw-logs` — resolved from wp-load.php the same way
     * HubInstallCommand resolves `wp-content/plugins`. The directory itself
     * needn't exist yet (nothing has logged), only its `wp-content` parent.
     */
    public static function resolveLogDir(string $themeDir): ?string
    {
        $wpLoad = WpLoader::locate($themeDir);
        if ($wpLoad === null) {
            return null;
        }

        $wpContent = dirname($wpLoad) . '/wp-content';

        return is_dir($wpContent) ? $wpContent . '/taw-logs' : null;
    }
}
