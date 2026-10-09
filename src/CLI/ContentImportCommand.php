<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Content\Importer;

/**
 * `bin/taw content:import <file> [--dry-run] [--yes] [--policy=update]`
 *
 * Mandatory dry-run: without `--yes` the command prints a field-level diff
 * of exactly what would change and exits **without writing anything**.
 * `--yes` applies it — and {@see \TAW\Core\Content\Importer::apply()}
 * writes a full rollback snapshot to `wp-content/uploads/taw-private/`
 * before it touches the database.
 *
 * Accepts either a full snapshot (`content:export` output) or a change-set
 * (`content:diff` output).
 */
class ContentImportCommand extends Command
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
            ->setName('content:import')
            ->setDescription('Import a content snapshot or change-set — dry-run by default, --yes to apply (writes a rollback snapshot first)')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/taw content:import site.json</info>              dry-run: show the diff, write nothing
                  <info>php bin/taw content:import site.json --yes</info>        apply it
                  <info>php bin/taw content:import changes.json --yes --policy=create</info>  create new records only, skip existing
                  <info>php bin/taw content:import --undo</info>                 preview undoing the last import
                  <info>php bin/taw content:import --undo --yes</info>           undo it (values edited since are kept)
                HELP)
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to the snapshot / change-set JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Force dry-run even if --yes is given')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Apply the changes (otherwise the command only previews them)')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, 'Conflict policy for records that already exist: update | create | skip', 'update')
            ->addOption('with-settings', null, InputOption::VALUE_NONE, 'Also apply environment-settings options (permalink_structure, timezone, sticky_posts, …) — skipped by default')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Import as this user (login, email or ID); default: the first administrator')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the plan/report as JSON')
            ->addOption('undo', null, InputOption::VALUE_OPTIONAL, 'Undo an import from its journal (default: the last one); with --yes', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        $undo = $input->getOption('undo');
        $undoing = $undo !== false;
        $data = [];
        if (!$undoing) {
            $file = (string) $input->getArgument('file');
            if (!is_file($file) || !is_readable($file)) {
                $io->error($file === '' ? 'Name the snapshot or change-set to import (or --undo).' : "Cannot read file: {$file}");
                return Command::FAILURE;
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                $io->error('That file is not valid JSON.');
                return Command::FAILURE;
            }
        }

        $policy = (string) $input->getOption('policy');
        if (!in_array($policy, Importer::POLICIES, true)) {
            $io->error("Invalid --policy '{$policy}'. Use: update | create | skip");
            return Command::FAILURE;
        }

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

        // Import as a user, like an admin's save: with none, WordPress
        // filters content through kses and fields saved as code lose their
        // markup, and new posts get no author.
        $user = WpLoader::cliUser((string) $input->getOption('user'));
        if ($user === null) {
            $io->error("No such user: '" . $input->getOption('user') . "'.");
            return Command::FAILURE;
        }
        if ($user > 0) {
            wp_set_current_user($user);
        }

        $importer = new Importer();
        $apply = $input->getOption('yes') && !$input->getOption('dry-run');

        if ($undoing) {
            return $this->undo($io, $output, $importer, is_string($undo) ? $undo : '', $apply, $asJson);
        }

        $plan = $importer->plan($data);

        if (!$apply) {
            $this->renderPlan($io, $output, $plan, $asJson);
            if (!$asJson) {
                $io->note('Dry run — nothing was written. Re-run with --yes to apply.');
            }
            return Command::SUCCESS;
        }

        $report = $importer->apply($data, [
            'policy'           => $policy,
            'include_settings' => (bool) $input->getOption('with-settings'),
        ]);

        $ok = $report['error'] === null && $report['failed'] === [];
        if ($asJson) {
            // Tools read `failed`, `error` and `journal`; the exit code only
            // says whether anything was imported at all.
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $report['rollback_path'] === null && $report['error'] !== null ? Command::FAILURE : Command::SUCCESS;
        }

        if ($report['error'] !== null && $report['rollback_path'] === null) {
            $io->error((string) $report['error']);
            return Command::FAILURE;
        }

        if ($ok) {
            $io->success('Import applied.');
        } else {
            $io->warning('Import applied in part.');
        }
        $io->definitionList(
            ['Created' => (string) count($report['created'])],
            ['Updated' => (string) count($report['updated'])],
            ['Skipped' => (string) count($report['skipped'])],
            ['Deleted' => (string) count($report['deleted'])],
            ['Failed' => (string) count($report['failed'])],
            ['Media sideloaded' => (string) $report['media_sideloaded']],
            ['Rollback snapshot' => (string) ($report['rollback_path'] ?? '(none)')],
            ['Journal' => $report['journal'] !== null ? basename((string) $report['journal']) . ' (undo: content:import --undo --yes)' : '(none)'],
        );
        foreach ($report['failed'] as $failure) {
            $io->error((string) $failure);
        }
        if ($report['error'] !== null) {
            $io->error((string) $report['error']);
        }
        foreach (array_merge($report['registry_drift'] ?? [], $report['warnings'] ?? []) as $warning) {
            $io->warning((string) $warning);
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function undo(SymfonyStyle $io, OutputInterface $output, Importer $importer, string $journal, bool $apply, bool $asJson): int
    {
        $found = $journal === '' ? Importer::latestJournal() : Importer::readJournal($journal);
        if ($found === null) {
            $io->error($journal === '' ? 'No import to undo.' : "That isn't an import journal in uploads/taw-private: {$journal}");
            return Command::FAILURE;
        }

        if (!$apply) {
            $counts = array_count_values(array_map(static fn (array $e): string => (string) ($e['action'] ?? ''), $found['entries']));
            $io->text(sprintf('<info>%s</info> — import of %s, %s', basename($found['path']), $found['source'] ?? 'a file', $found['created_at']));
            $io->definitionList(
                ['Records it updated' => (string) ($counts['updated'] ?? 0)],
                ['Records it created (deleted on undo)' => (string) ($counts['created'] ?? 0)],
                ['Records it deleted (restored on undo)' => (string) ($counts['deleted'] ?? 0)],
            );
            $io->note('Preview — nothing was changed. Re-run with --yes to undo. Values edited since the import are kept.');
            return Command::SUCCESS;
        }

        $result = $importer->undo($found['path']);
        if ($asJson) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $result['error'] === null ? Command::SUCCESS : Command::FAILURE;
        }
        if ($result['error'] !== null) {
            $io->error($result['error']);
            return Command::FAILURE;
        }
        $io->success(sprintf('Undone: %d values restored, %d records deleted, %d recreated.', $result['restored'], $result['deleted'], $result['recreated']));
        foreach ($result['kept'] as $kept) {
            $io->warning("Kept: {$kept}");
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderPlan(SymfonyStyle $io, OutputInterface $output, array $plan, bool $asJson): void
    {
        if ($asJson) {
            $output->writeln((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        foreach ($plan['registry_drift'] ?? [] as $note) {
            $io->warning((string) $note);
        }

        $rows = [];
        foreach ($plan['records'] ?? [] as $record) {
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            if ($changes === [] && ($record['op'] ?? '') !== 'would-delete') {
                continue;
            }
            $summary = [];
            foreach ($changes as $field => $delta) {
                $summary[] = $field . ': ' . ($delta['status'] ?? '');
            }
            $rows[] = [
                ($record['kind'] ?? '') . ':' . ($record['type'] ?? $record['key'] ?? '') . ':' . ($record['slug'] ?? ''),
                (string) ($record['op'] ?? ''),
                implode(', ', $summary) ?: '—',
            ];
        }

        if ($rows === []) {
            $io->text('No changes — the snapshot matches the current site.');
            return;
        }

        $io->table(['Record', 'Op', 'Changes'], $rows);
        foreach ($plan['warnings'] ?? [] as $warning) {
            $io->warning((string) $warning);
        }
    }
}
