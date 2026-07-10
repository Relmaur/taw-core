<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Metabox\Metabox;

/**
 * Write a single Metabox/OptionsPage field's value for a post, running the
 * exact same sanitization rules as the real admin form save (Metabox::save())
 * and the Visual Editor's REST endpoint (VisualEditorEndpoint) — the write
 * half of the same primitive, minus the Visual Editor's 'editor' => true
 * gate, since CLI access is already a trusted, direct-DB-write context.
 *
 * This exists so an agent (or a script) never has to hand-encode a
 * repeater's JSON shape or guess a field's sanitization rules — it asks
 * the framework, which already knows both, exactly once.
 *
 * Boots WordPress (same pattern as InspectCommand) — field configs only
 * exist once WordPress is loaded.
 */
class FieldsSetCommand extends Command
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
            ->setName('fields:set')
            ->setDescription('Write a Metabox/OptionsPage field value for a post, sanitized exactly like the real admin form save')
            ->setHelp(<<<'HELP'
                Looks the field up in the live Metabox field registry to determine its
                type, sanitizes the given value with the same rules Metabox::save() and
                the Visual Editor's REST endpoint use, then writes it via
                update_post_meta(). Reports the value actually stored (post-sanitization)
                so you can confirm nothing was silently stripped.

                Plain fields — pass the value directly:
                  <info>php bin/taw fields:set 42 hero_heading "Welcome"</info>

                Repeaters / post_select / files — value must be JSON. Prefer --file
                over inline JSON to sidestep shell quoting:
                  <info>php bin/taw fields:set 42 team_members --file=/tmp/team.json</info>
                  <info>php bin/taw fields:set 42 team_members '[{"name":"Ada","role":"CTO"}]'</info>

                Preview without writing:
                  <info>php bin/taw fields:set 42 hero_heading "Welcome" --dry-run</info>
                HELP)
            ->addArgument('post_id', InputArgument::REQUIRED, 'Post ID the field is stored against')
            ->addArgument('field_id', InputArgument::REQUIRED, "Field ID, without the meta key prefix (e.g. 'hero_heading', or 'hero_cta_text' for a group sub-field)")
            ->addArgument('value', InputArgument::OPTIONAL, 'The new value. Required unless --file is given. For repeater/files/multi post_select fields, must be a JSON string.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Read the value from a file instead of the value argument — recommended for repeaters, to avoid shell JSON-quoting issues')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sanitize and report what would be saved, without writing to the database')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $postId = (int) $input->getArgument('post_id');
        $fieldId = (string) $input->getArgument('field_id');
        $valueArg = $input->getArgument('value');
        $filePath = $input->getOption('file');

        if ($filePath === null && $valueArg === null) {
            $io->error('Provide a value argument or --file.');
            return Command::FAILURE;
        }

        if ($filePath !== null && $valueArg !== null) {
            $io->error('Provide either a value argument or --file, not both.');
            return Command::FAILURE;
        }

        if ($filePath !== null) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                $io->error("Cannot read file: {$filePath}");
                return Command::FAILURE;
            }
            $rawValue = (string) file_get_contents($filePath);
        } else {
            $rawValue = (string) $valueArg;
        }

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory.');
            return Command::FAILURE;
        }

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        require $wpLoad;

        if (!get_post($postId)) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        $fieldConfig = Metabox::get_field_config($fieldId);
        if ($fieldConfig === null) {
            $io->error("Unknown field: '{$fieldId}'. Run 'php bin/taw inspect --json' to see registered field IDs per block.");
            return Command::FAILURE;
        }

        $type = $fieldConfig['type'] ?? 'text';
        $prefix = $fieldConfig['prefix'] ?? '_taw_';
        $metaKey = $prefix . $fieldId;

        // Same dispatch and sanitizers VisualEditorEndpoint uses for its
        // REST-driven saves — repeaters need their own entry point since
        // they sanitize an array of rows, not a single scalar.
        $sanitized = $type === 'repeater'
            ? Metabox::sanitizeRepeaterRows($fieldConfig, $rawValue)
            : Metabox::sanitizeValue($fieldConfig, $rawValue);

        if ($dryRun) {
            $this->report($io, $output, $asJson, $postId, $fieldId, $type, $metaKey, $sanitized, saved: false);
            return Command::SUCCESS;
        }

        // Mirrors VisualEditorEndpoint::save_single_field() — no wp_slash()
        // here deliberately: that's only needed to counteract
        // update_post_meta()'s internal wp_unslash() when the source value
        // came from a magic-quoted superglobal ($_POST). CLI/JSON-sourced
        // values were never slashed to begin with.
        $result = update_post_meta($postId, $metaKey, $sanitized);

        if ($result === false) {
            $current = get_post_meta($postId, $metaKey, true);
            if ($current != $sanitized) {
                $io->error("Failed to save field: {$fieldId}");
                return Command::FAILURE;
            }
        }

        $this->report($io, $output, $asJson, $postId, $fieldId, $type, $metaKey, $sanitized, saved: true);
        return Command::SUCCESS;
    }

    private function report(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $asJson,
        int $postId,
        string $fieldId,
        string $type,
        string $metaKey,
        mixed $sanitized,
        bool $saved
    ): void {
        if ($asJson) {
            $output->writeln((string) json_encode([
                'post_id' => $postId,
                'field_id' => $fieldId,
                'type' => $type,
                'meta_key' => $metaKey,
                'saved' => $saved,
                'value' => $sanitized,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $io->success(($saved ? 'Saved' : '[dry-run] Would save') . " {$fieldId} ({$type}) on post {$postId}.");
        $io->section('Stored value (post-sanitization)');
        $io->text(is_string($sanitized) ? $sanitized : (string) json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
