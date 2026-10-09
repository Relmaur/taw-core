<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Content\Defaults;

/**
 * `bin/taw content:defaults [--apply] [--undo=<journal>] [--json]`
 *
 * Lists every empty field that has a `defaults` value (metaboxes on the
 * posts they apply to, and options pages) and, with `--apply`, saves those
 * defaults to the database. A journal of the writes goes to
 * `uploads/taw-private/` first; `--undo` reverses it.
 */
class ContentDefaultsCommand extends Command
{
    public function __construct(private readonly string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('content:defaults')
            ->setDescription('Save the fields\' defaults to the database (preview by default, --apply to write)')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/taw content:defaults</info>                 preview: every empty field and what it would get
                  <info>php bin/taw content:defaults --apply</info>         save them (writes a journal first)
                  <info>php bin/taw content:defaults --undo=defaults-20261009-120000.json</info>
                HELP)
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the defaults (otherwise only preview)')
            ->addOption('undo', null, InputOption::VALUE_REQUIRED, 'Reverse a journal from uploads/taw-private (fields edited since are kept)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Write as this user (login, email or ID); default: the first administrator')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the plan/report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

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

        // Write as an admin's save would: fields that keep markup for users
        // allowed unfiltered HTML (`sanitize => code`) are sanitized per user.
        $user = WpLoader::cliUser((string) $input->getOption('user'));
        if ($user === null) {
            $io->error("No such user: '" . $input->getOption('user') . "'.");
            return Command::FAILURE;
        }
        if ($user > 0) {
            wp_set_current_user($user);
        }

        $defaults = new Defaults();

        $undo = $input->getOption('undo');
        if (is_string($undo) && $undo !== '') {
            $result = $defaults->undo($undo);
            if ($asJson) {
                $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } elseif ($result['error'] !== null) {
                $io->error($result['error']);
            } else {
                $io->success(sprintf('Restored %d fields; kept %d edited since.', $result['restored'], $result['kept']));
            }
            return $result['error'] === null ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$input->getOption('apply')) {
            $plan = $defaults->plan();
            if ($asJson) {
                $output->writeln((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }
            if ($plan['records'] === []) {
                $io->success('Every field with a default already has a value.');
                return Command::SUCCESS;
            }
            $io->table(['Where', 'Field', 'Value'], array_map(
                static fn (array $r): array => [$r['label'], $r['field'] . ($r['altered'] ? ' *' : ''), self::short($r['value'])],
                $plan['records']
            ));
            foreach ($plan['warnings'] as $warning) {
                $io->warning($warning);
            }
            $io->note(sprintf('Preview: %d fields on %d posts and %d options would be saved. Nothing was written; re-run with --apply.', count($plan['records']), $plan['posts'], $plan['options']));
            return Command::SUCCESS;
        }

        $report = $defaults->apply();
        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $report['error'] === null ? Command::SUCCESS : Command::FAILURE;
        }
        if ($report['error'] !== null) {
            $io->error($report['error']);
            return Command::FAILURE;
        }
        foreach ($report['warnings'] as $warning) {
            $io->warning($warning);
        }
        if ($report['written'] === 0) {
            $io->success('Every field with a default already has a value.');
            return Command::SUCCESS;
        }
        $io->success(sprintf('Saved %d defaults (%d posts, %d options). Undo with --undo=%s', $report['written'], $report['posts'], $report['options'], basename((string) $report['journal'])));

        return Command::SUCCESS;
    }

    private static function short(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)));

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }
}
