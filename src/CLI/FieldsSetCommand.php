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
use TAW\Core\OptionsPage\OptionsPage;

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
 *
 * Pass the literal string 'options' as the target instead of a numeric post
 * ID to write a site-wide OptionsPage field instead of a per-post Metabox
 * field — see {@see self::execute()}.
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
            ->setDescription('Write a Metabox/OptionsPage field value for a post (or a site-wide option), sanitized exactly like the real admin form save')
            ->setHelp(<<<'HELP'
                Looks the field up in the live Metabox/OptionsPage field registry to
                determine its type, sanitizes the given value with the same rules
                Metabox::save() and the Visual Editor's REST endpoint use, then writes it
                via update_post_meta() (or update_option(), for an OptionsPage field).
                Reports the value actually stored (post-sanitization) so you can confirm
                nothing was silently stripped.

                Plain fields — pass the value directly:
                  <info>php bin/taw fields:set 42 hero_heading "Welcome"</info>

                Repeaters / post_select / files / gradient_text / hubspot_form / link — value
                must be JSON. Prefer --file over inline JSON to sidestep shell quoting:
                  <info>php bin/taw fields:set 42 team_members --file=/tmp/team.json</info>
                  <info>php bin/taw fields:set 42 team_members '[{"name":"Ada","role":"CTO"}]'</info>

                A field id shared by two fieldsets on the post's type (with different
                prefixes) is ambiguous; pass the qualified id or the meta key:
                  <info>php bin/taw fields:set 42 book_details.subtitle "Second edition"</info>

                OptionsPage field — pass the literal 'options' instead of a post ID:
                  <info>php bin/taw fields:set options company_phone "555-1234"</info>

                Preview without writing:
                  <info>php bin/taw fields:set 42 hero_heading "Welcome" --dry-run</info>
                HELP)
            ->addArgument('post_id', InputArgument::REQUIRED, "Post ID the field is stored against, or the literal 'options' for a site-wide OptionsPage field")
            ->addArgument('field_id', InputArgument::REQUIRED, "Field ID without the meta key prefix (e.g. 'hero_heading', or 'hero_cta_text' for a group sub-field), a qualified id ('hero.hero_heading'), or the full meta key")
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
        $targetArg = (string) $input->getArgument('post_id');
        $isOptionsTarget = $targetArg === 'options';
        $postId = $isOptionsTarget ? 0 : (int) $targetArg;
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
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        if (!$isOptionsTarget && !get_post($postId)) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        if ($isOptionsTarget) {
            $fieldConfig = OptionsPage::getFieldConfig($fieldId);
        } else {
            // Qualified id, meta key or bare id, resolved for this post's type (ADR-0008).
            $resolved = FieldRef::resolve($postId, $fieldId);
            if ($resolved['ambiguous'] !== []) {
                $io->error("Field '{$fieldId}' matches several fields on this post: " . implode(', ', $resolved['ambiguous'])
                    . '. Pass the qualified id (fieldset.field) or the meta key.');
                return Command::FAILURE;
            }
            $fieldConfig = $resolved['config'];
        }

        if ($fieldConfig === null) {
            $registry = $isOptionsTarget ? 'OptionsPage' : 'Metabox';
            $io->error("Unknown {$registry} field: '{$fieldId}'. Run 'php bin/taw inspect --json' to see registered field IDs per block.");
            return Command::FAILURE;
        }

        $type = $fieldConfig['type'] ?? 'text';
        $storageKey = $isOptionsTarget
            ? ($fieldConfig['prefix'] ?? '_taw_') . $fieldId
            : Metabox::metaKeyOf($fieldConfig);
        $target = $isOptionsTarget ? 'options' : $postId;

        if ($dryRun) {
            // Same sanitization writeMeta()/writeOption() would apply, without the write.
            $sanitized = Metabox::sanitizeForStorage($fieldConfig, $rawValue);
            $this->report($io, $output, $asJson, $target, $fieldId, $type, $storageKey, $sanitized, saved: false);
            return Command::SUCCESS;
        }

        // The one shared write primitive per storage backend — same sanitize +
        // wp_slash + update_post_meta sequence an admin metabox save runs
        // (Metabox::save()), or update_option() for an OptionsPage field
        // (OptionsPage::writeOption()) — and the same ones TAW\Core\Content\Importer
        // uses. Returns the value actually stored.
        $sanitized = $isOptionsTarget
            ? OptionsPage::writeOption($fieldConfig, $rawValue)
            : Metabox::writeMeta($postId, $fieldConfig, $rawValue);

        $this->report($io, $output, $asJson, $target, $fieldId, $type, $storageKey, $sanitized, saved: true);
        return Command::SUCCESS;
    }

    private function report(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $asJson,
        int|string $target,
        string $fieldId,
        string $type,
        string $storageKey,
        mixed $sanitized,
        bool $saved
    ): void {
        $isOptions = $target === 'options';

        if ($asJson) {
            $output->writeln((string) json_encode(array_merge(
                $isOptions ? ['scope' => 'options'] : ['post_id' => $target],
                [
                    'field_id' => $fieldId,
                    'type' => $type,
                    $isOptions ? 'option_name' : 'meta_key' => $storageKey,
                    'saved' => $saved,
                    'value' => $sanitized,
                ]
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $where = $isOptions ? 'as a site option' : "on post {$target}";
        $io->success(($saved ? 'Saved' : '[dry-run] Would save') . " {$fieldId} ({$type}) {$where}.");
        $io->section('Stored value (post-sanitization)');
        $io->text(is_string($sanitized) ? $sanitized : (string) json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
