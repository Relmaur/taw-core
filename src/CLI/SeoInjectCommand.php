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
 * Write an optimized copy JSON dump (SeoExtractCommand's own output shape,
 * after an agent has edited the text values) back to a post's Metabox
 * fields — the write half of the SEO/copy audit loop.
 *
 * Deliberately narrower than FieldsSetCommand: only ever writes fields the
 * live registry currently reports as text/textarea/wysiwyg (or repeaters
 * whose touched sub-fields are), and never touches core post data
 * (post_title/post_content/post_status) even if present in the input file
 * — same hard boundary FieldsSetCommand documents. Every field in the
 * input is validated *before* any write happens; if anything fails
 * validation, nothing is written — a partially-applied copy rewrite is a
 * worse failure mode than a rejected one.
 *
 * Repeaters need special handling: extraction only kept the text-like
 * sub-fields of each row (an image/url sub-field on the same row was
 * dropped entirely, on purpose, to save tokens). A naive write-back would
 * therefore replace the whole repeater's stored value with rows missing
 * every non-text sub-field — silently deleting them. Instead, each
 * optimized row is merged into the CURRENT live row at the same index,
 * overwriting only the keys the optimized JSON actually contains.
 *
 * Boots WordPress (same pattern as FieldsSetCommand) — field configs and
 * post_meta only exist once WordPress is loaded.
 */
class SeoInjectCommand extends Command
{
    private const TEXT_TYPES = ['text', 'textarea', 'wysiwyg'];

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('seo:inject')
            ->setDescription('Write an optimized copy JSON dump (from seo:extract) back to a post\'s Metabox fields')
            ->setHelp(<<<'HELP'
                Reads a JSON file shaped like seo:extract's own output, validates every
                field against the live Metabox registry, and writes the (sanitized)
                values back. Repeater rows are merged into the current live row by
                index — only the sub-fields present in the input are overwritten, every
                other sub-field on that row (images, URLs, etc.) is left untouched.

                Every field is validated before anything is written — if any field
                fails validation, nothing is written at all.

                Examples:
                  <info>php bin/taw seo:inject 42</info>
                  <info>php bin/taw seo:inject 42 --input=.taw/seo-optimized.json --dry-run</info>
                HELP)
            ->addArgument('post_id', InputArgument::REQUIRED, 'Post ID to write copy back to')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'File path to read the optimized JSON dump from', '.taw/seo-optimized.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report what would be written, without writing to the database')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $postId = (int) $input->getArgument('post_id');
        $inputPath = (string) $input->getOption('input');
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = (bool) $input->getOption('json');

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            $io->error("Cannot read input file: {$inputPath}");
            return Command::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            $io->error("Invalid input file: expected a JSON object with a 'blocks' array (the shape seo:extract produces).");
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

        if (!get_post($postId)) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        // Pass 1 — validate every field entry against the live registry
        // before writing anything. Only 'blocks[].fields[]' is ever read;
        // any other top-level key (post_title, etc.) is silently ignored —
        // this command never touches core post data.
        $errors = [];
        $plan = [];

        foreach ($decoded['blocks'] as $block) {
            foreach ((array) ($block['fields'] ?? []) as $fieldEntry) {
                $fieldId = (string) ($fieldEntry['field_id'] ?? '');
                if ($fieldId === '') {
                    $errors[] = "Field entry missing 'field_id' in block '{$block['block_id']}'.";
                    continue;
                }

                $fieldConfig = Metabox::get_field_config($fieldId);
                if ($fieldConfig === null) {
                    $errors[] = "Unknown field: '{$fieldId}' — not in the live Metabox registry (renamed or removed since seo:extract ran?).";
                    continue;
                }

                $liveType = $fieldConfig['type'] ?? 'text';

                if ($liveType === 'repeater') {
                    if (!isset($fieldEntry['rows']) || !is_array($fieldEntry['rows'])) {
                        $errors[] = "Field '{$fieldId}' is a repeater — expected a 'rows' array.";
                        continue;
                    }

                    [$mergedRows, $rowErrors] = $this->planRepeaterMerge($postId, $fieldId, $fieldConfig, $fieldEntry['rows']);
                    if ($rowErrors !== []) {
                        $errors = [...$errors, ...$rowErrors];
                        continue;
                    }

                    $plan[] = [
                        'field_id' => $fieldId,
                        'meta_key' => ($fieldConfig['prefix'] ?? '_taw_') . $fieldId,
                        'type' => 'repeater',
                        'value' => (string) wp_json_encode($mergedRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'preview' => $mergedRows,
                    ];
                    continue;
                }

                if (!in_array($liveType, self::TEXT_TYPES, true)) {
                    $errors[] = "Field '{$fieldId}' is registered as type '{$liveType}' — seo:inject only writes text/textarea/wysiwyg fields (and repeaters of them). Use fields:set for other field types.";
                    continue;
                }

                if (!array_key_exists('value', $fieldEntry)) {
                    $errors[] = "Field '{$fieldId}' missing 'value'.";
                    continue;
                }

                $sanitized = Metabox::sanitizeValue($fieldConfig, (string) $fieldEntry['value']);
                $plan[] = [
                    'field_id' => $fieldId,
                    'meta_key' => ($fieldConfig['prefix'] ?? '_taw_') . $fieldId,
                    'type' => $liveType,
                    'value' => $sanitized,
                    'preview' => $sanitized,
                ];
            }
        }

        if ($errors !== []) {
            $io->error('Validation failed — nothing was written:');
            foreach ($errors as $error) {
                $io->text(" - {$error}");
            }
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->report($io, $output, $asJson, $postId, $plan, saved: false);
            return Command::SUCCESS;
        }

        foreach ($plan as $item) {
            $result = update_post_meta($postId, $item['meta_key'], $item['value']);
            if ($result === false) {
                $current = get_post_meta($postId, $item['meta_key'], true);
                if ($current != $item['value']) {
                    $io->error("Failed to save field: {$item['field_id']}");
                    return Command::FAILURE;
                }
            }
        }

        $this->report($io, $output, $asJson, $postId, $plan, saved: true);
        return Command::SUCCESS;
    }

    /**
     * Merges the optimized rows into the CURRENT live repeater value at
     * matching indices — never a wholesale replace. Refuses (rather than
     * guessing) if the row count has drifted since seo:extract ran, since
     * index-based alignment is only meaningful if nothing was added,
     * removed, or reordered in the admin in the meantime.
     *
     * @param mixed[] $optimizedRows Rows from the (agent-edited, untrusted) input file.
     * @return array{0: array[], 1: string[]} [mergedRows, errors]
     */
    private function planRepeaterMerge(int $postId, string $fieldId, array $fieldConfig, array $optimizedRows): array
    {
        $prefix = $fieldConfig['prefix'] ?? '_taw_';
        $liveRows = Metabox::get_repeater($postId, $fieldId, $prefix);

        if (count($liveRows) !== count($optimizedRows)) {
            return [[], [
                "Repeater '{$fieldId}' has " . count($liveRows) . ' live row(s) but the input has ' .
                count($optimizedRows) . " — refusing to guess row alignment. Re-run seo:extract to " .
                're-sync with the current data, then re-apply your edits.',
            ]];
        }

        $subFieldMap = [];
        foreach ($fieldConfig['fields'] ?? [] as $sf) {
            if (!empty($sf['id'])) {
                $subFieldMap[$sf['id']] = $sf;
            }
        }

        $errors = [];
        $merged = [];

        foreach ($optimizedRows as $index => $optimizedRow) {
            if (!is_array($optimizedRow)) {
                $errors[] = "Repeater '{$fieldId}' row {$index} is not an object.";
                continue;
            }

            $liveRow = is_array($liveRows[$index] ?? null) ? $liveRows[$index] : [];

            foreach ($optimizedRow as $key => $value) {
                $sub = $subFieldMap[$key] ?? null;
                if ($sub === null) {
                    $errors[] = "Repeater '{$fieldId}' row {$index}: unknown sub-field '{$key}'.";
                    continue;
                }

                $subType = $sub['type'] ?? 'text';

                if ($subType === 'repeater') {
                    if (!is_array($value)) {
                        $errors[] = "Repeater '{$fieldId}' row {$index}: sub-field '{$key}' is a nested repeater — expected an array of rows.";
                        continue;
                    }
                    $nestedLive = is_array($liveRow[$key] ?? null) ? $liveRow[$key] : [];
                    // Reuse the same merge logic one level deeper by wrapping the
                    // nested live rows behind a throwaway config carrying the
                    // nested sub-field schema.
                    [$nestedMerged, $nestedErrors] = $this->mergeNestedRepeaterRows($nestedLive, $value, $sub['fields'] ?? []);
                    if ($nestedErrors !== []) {
                        $errors = [...$errors, ...$nestedErrors];
                        continue;
                    }
                    $liveRow[$key] = $nestedMerged;
                    continue;
                }

                if (!in_array($subType, self::TEXT_TYPES, true)) {
                    $errors[] = "Repeater '{$fieldId}' row {$index}: sub-field '{$key}' is type '{$subType}' — only text/textarea/wysiwyg sub-fields can be written by seo:inject.";
                    continue;
                }

                $liveRow[$key] = Metabox::sanitizeValue($sub, (string) $value);
            }

            $merged[] = $liveRow;
        }

        return [$merged, $errors];
    }

    /**
     * Same index-matched merge as planRepeaterMerge(), for a nested
     * repeater sub-field (rows stored as a plain decoded array inside the
     * parent row, not a JSON string — mirrors how sanitizeRepeaterRows
     * stores nested repeaters to avoid double-encoding).
     *
     * @param array[] $liveRows
     * @param mixed[] $optimizedRows Rows from the (agent-edited, untrusted) input file.
     * @param array[] $subFields
     * @return array{0: array[], 1: string[]}
     */
    private function mergeNestedRepeaterRows(array $liveRows, array $optimizedRows, array $subFields): array
    {
        if (count($liveRows) !== count($optimizedRows)) {
            return [[], ['Nested repeater row count mismatch — re-run seo:extract to re-sync before re-applying edits.']];
        }

        $subFieldMap = [];
        foreach ($subFields as $sf) {
            if (!empty($sf['id'])) {
                $subFieldMap[$sf['id']] = $sf;
            }
        }

        $errors = [];
        $merged = [];

        foreach ($optimizedRows as $index => $optimizedRow) {
            if (!is_array($optimizedRow)) {
                $errors[] = "Nested repeater row {$index} is not an object.";
                continue;
            }

            $liveRow = is_array($liveRows[$index] ?? null) ? $liveRows[$index] : [];

            foreach ($optimizedRow as $key => $value) {
                $sub = $subFieldMap[$key] ?? null;
                $subType = $sub['type'] ?? null;

                if ($sub === null || !in_array($subType, self::TEXT_TYPES, true)) {
                    $errors[] = "Nested repeater row {$index}: sub-field '{$key}' is not a writable text field.";
                    continue;
                }

                $liveRow[$key] = Metabox::sanitizeValue($sub, (string) $value);
            }

            $merged[] = $liveRow;
        }

        return [$merged, $errors];
    }

    private function report(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $asJson,
        int $postId,
        array $plan,
        bool $saved
    ): void {
        if ($asJson) {
            $output->writeln((string) json_encode([
                'post_id' => $postId,
                'saved' => $saved,
                'fields' => array_map(
                    static fn (array $item) => [
                        'field_id' => $item['field_id'],
                        'type' => $item['type'],
                        'value' => $item['preview'],
                    ],
                    $plan
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $io->success(($saved ? 'Saved' : '[dry-run] Would save') . ' ' . count($plan) . " field(s) on post {$postId}.");
        foreach ($plan as $item) {
            $io->section("{$item['field_id']} ({$item['type']})");
            $io->text(is_string($item['preview']) ? $item['preview'] : (string) json_encode($item['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
