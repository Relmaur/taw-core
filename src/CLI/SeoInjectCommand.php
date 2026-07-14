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
use TAW\Core\Seo\SeoMeta;

/**
 * Write an optimized copy JSON dump (SeoExtractCommand's own output shape,
 * after an agent has edited the text values) back to a post's Metabox
 * fields and/or SEO meta — the write half of the SEO/copy audit loop.
 *
 * Deliberately narrower than FieldsSetCommand: only ever writes fields the
 * live registry currently reports as text/textarea/wysiwyg (or repeaters
 * whose touched sub-fields are), and never touches core post data
 * (post_title/post_content/post_status) even if present in the input file
 * — same hard boundary FieldsSetCommand documents. Every field in a post's
 * input is validated *before* any write happens for that post; if anything
 * fails validation, nothing is written for that post — a partially-applied
 * copy rewrite is a worse failure mode than a rejected one.
 *
 * Repeaters need special handling: extraction only kept the text-like
 * sub-fields of each row (an image/url sub-field on the same row was
 * dropped entirely, on purpose, to save tokens). A naive write-back would
 * therefore replace the whole repeater's stored value with rows missing
 * every non-text sub-field — silently deleting them. Instead, each
 * optimized row is merged into the CURRENT live row at the same index,
 * overwriting only the keys the optimized JSON actually contains.
 *
 * SEO meta (meta_title/meta_description/og_image_id, under an optional
 * top-level 'seo_meta' key) is validated and written via TAW\Core\Seo\SeoMeta,
 * which resolves fresh — at write time, not from what the input recorded —
 * whether TAW's own fields or an active Yoast install's are authoritative.
 *
 * --all applies a {"posts": [...]} site-wide dump (SeoExtractCommand --all's
 * own output shape). Each post is validated and applied INDEPENDENTLY —
 * unlike the all-or-nothing rule within a single post, one post failing
 * validation doesn't block every other post in the same run; the summary
 * reports exactly which posts succeeded and which didn't, and why.
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
            ->setDescription('Write an optimized copy JSON dump (from seo:extract) back to a post\'s Metabox fields and/or SEO meta')
            ->setHelp(<<<'HELP'
                Reads a JSON file shaped like seo:extract's own output, validates every
                field against the live Metabox registry, and writes the (sanitized)
                values back. Repeater rows are merged into the current live row by
                index — only the sub-fields present in the input are overwritten, every
                other sub-field on that row (images, URLs, etc.) is left untouched.

                Every field is validated before anything is written for a given post —
                if any field on that post fails validation, nothing is written for it.

                Examples:
                  <info>php bin/taw seo:inject 42</info>
                  <info>php bin/taw seo:inject 42 --input=.taw/seo-optimized.json --dry-run</info>
                  <info>php bin/taw seo:inject --all --input=.taw/seo-site-optimized.json</info>
                HELP)
            ->addArgument('post_id', InputArgument::OPTIONAL, 'Post ID to write copy back to (omit when using --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Apply a {"posts": [...]} site-wide dump instead of a single post_id')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'File path to read the optimized JSON dump from (default: .taw/seo-optimized.json, or .taw/seo-site-optimized.json with --all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report what would be written, without writing to the database')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = (bool) $input->getOption('all');
        $postIdArg = $input->getArgument('post_id');
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = (bool) $input->getOption('json');

        if (!$all && $postIdArg === null) {
            $io->error('Provide a post_id, or pass --all to apply a site-wide dump.');
            return Command::FAILURE;
        }

        $inputPath = $input->getOption('input');
        $inputPath = is_string($inputPath) ? $inputPath : ($all ? '.taw/seo-site-optimized.json' : '.taw/seo-optimized.json');

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            $io->error("Cannot read input file: {$inputPath}");
            return Command::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (!is_array($decoded)) {
            $io->error('Invalid input file: not valid JSON.');
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

        if ($all) {
            return $this->executeAll($io, $output, $decoded, $dryRun, $asJson);
        }

        if (!isset($decoded['blocks']) && !isset($decoded['seo_meta'])) {
            $io->error("Invalid input file: expected 'blocks' and/or 'seo_meta' (the shape seo:extract produces).");
            return Command::FAILURE;
        }

        $postId = (int) $postIdArg;
        if (!get_post($postId)) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        [$plan, $seoMetaPlan, $errors] = $this->validatePost($postId, $decoded);

        if ($errors !== []) {
            $io->error('Validation failed — nothing was written:');
            foreach ($errors as $error) {
                $io->text(" - {$error}");
            }
            return Command::FAILURE;
        }

        if (!$dryRun) {
            $this->applyPost($postId, $plan, $seoMetaPlan);
        }

        $this->report($io, $output, $asJson, $postId, $plan, $seoMetaPlan, saved: !$dryRun);
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function executeAll(SymfonyStyle $io, OutputInterface $output, array $decoded, bool $dryRun, bool $asJson): int
    {
        if (!isset($decoded['posts']) || !is_array($decoded['posts'])) {
            $io->error("Invalid input file: expected a 'posts' array (the shape seo:extract --all produces).");
            return Command::FAILURE;
        }

        $results = [];

        foreach ($decoded['posts'] as $postDump) {
            $postId = (int) ($postDump['post_id'] ?? 0);
            if ($postId <= 0) {
                $results[] = ['post_id' => 0, 'ok' => false, 'errors' => ["Post entry missing 'post_id'."]];
                continue;
            }

            if (!get_post($postId)) {
                $results[] = ['post_id' => $postId, 'ok' => false, 'errors' => ["No post found with ID {$postId}."]];
                continue;
            }

            [$plan, $seoMetaPlan, $errors] = $this->validatePost($postId, $postDump);

            if ($errors !== []) {
                $results[] = ['post_id' => $postId, 'ok' => false, 'errors' => $errors];
                continue;
            }

            if (!$dryRun) {
                $this->applyPost($postId, $plan, $seoMetaPlan);
            }

            $results[] = ['post_id' => $postId, 'ok' => true, 'fields' => count($plan), 'seo_meta' => $seoMetaPlan !== null];
        }

        $succeeded = array_filter($results, static fn (array $r) => $r['ok']);
        $failed = array_filter($results, static fn (array $r) => !$r['ok']);

        if ($asJson) {
            $output->writeln((string) json_encode([
                'saved' => !$dryRun,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $io->success(sprintf(
                '%s %d/%d post(s).',
                $dryRun ? '[dry-run] Would apply' : 'Applied',
                count($succeeded),
                count($results)
            ));

            if ($failed !== []) {
                $io->section('Failed');
                foreach ($failed as $result) {
                    $io->text("Post {$result['post_id']}:");
                    foreach ($result['errors'] as $error) {
                        $io->text("  - {$error}");
                    }
                }
            }
        }

        // Per-post atomicity, not global — a partial site-wide run (some
        // posts applied, some rejected) is still reported as a command
        // failure so automation doesn't treat it as a clean success, but
        // every post that DID pass validation was still written for real.
        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Validates every 'blocks[].fields[]' entry and the optional 'seo_meta'
     * object against the live registry, WITHOUT writing anything — the
     * plan returned here is what applyPost() writes once dry-run/validation
     * has passed. Only 'blocks' and 'seo_meta' are ever read from $decoded;
     * any other key (post_title, etc.) is silently ignored — this command
     * never touches core post data.
     *
     * @param array<string, mixed> $decoded
     * @return array{0: array<int, array<string, mixed>>, 1: ?array<string, mixed>, 2: string[]}
     */
    private function validatePost(int $postId, array $decoded): array
    {
        $errors = [];
        $plan = [];

        foreach ((array) ($decoded['blocks'] ?? []) as $block) {
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

        [$seoMetaPlan, $seoMetaErrors] = $this->validateSeoMeta($decoded['seo_meta'] ?? null);
        $errors = [...$errors, ...$seoMetaErrors];

        return [$plan, $seoMetaPlan, $errors];
    }

    /**
     * @param mixed $seoMeta
     * @return array{0: ?array{meta_title: ?string, meta_description: ?string, og_image_id: ?int}, 1: string[]}
     */
    private function validateSeoMeta(mixed $seoMeta): array
    {
        if ($seoMeta === null) {
            return [null, []];
        }

        if (!is_array($seoMeta)) {
            return [null, ["'seo_meta' must be an object."]];
        }

        $hasTitle = array_key_exists('meta_title', $seoMeta) && $seoMeta['meta_title'] !== null;
        $hasDescription = array_key_exists('meta_description', $seoMeta) && $seoMeta['meta_description'] !== null;
        // 0 is seo:extract's own "no image set" sentinel, not a real
        // attachment ID — round-tripping an unedited dump back through
        // seo:inject must not treat "still 0" as an attempt to set the
        // image to ID 0. Only a genuine positive ID counts as "has an image."
        $hasImage = array_key_exists('og_image_id', $seoMeta)
            && $seoMeta['og_image_id'] !== null
            && (int) $seoMeta['og_image_id'] > 0;

        if (!$hasTitle && !$hasDescription && !$hasImage) {
            return [null, []];
        }

        $keys = SeoMeta::targetMetaKeys();
        if ($keys['source'] === 'unsupported') {
            return [null, [
                'seo_meta was provided, but a non-Yoast SEO plugin is active on this site — ' .
                'writing meta title/description/social image isn\'t supported for it yet. ' .
                'Remove seo_meta from the input, or use that plugin\'s own UI/API instead.',
            ]];
        }

        $ogImageId = null;
        if ($hasImage) {
            $ogImageId = (int) $seoMeta['og_image_id'];
            $attachment = get_post($ogImageId);
            if ($attachment === null || $attachment->post_type !== 'attachment') {
                return [null, ["seo_meta.og_image_id ({$ogImageId}) is not a real attachment."]];
            }
        }

        return [[
            'meta_title' => $hasTitle ? (string) $seoMeta['meta_title'] : null,
            'meta_description' => $hasDescription ? (string) $seoMeta['meta_description'] : null,
            'og_image_id' => $ogImageId,
        ], []];
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @param ?array{meta_title: ?string, meta_description: ?string, og_image_id: ?int} $seoMetaPlan
     */
    private function applyPost(int $postId, array $plan, ?array $seoMetaPlan): void
    {
        foreach ($plan as $item) {
            update_post_meta($postId, $item['meta_key'], $item['value']);
        }

        if ($seoMetaPlan !== null) {
            SeoMeta::write($postId, $seoMetaPlan['meta_title'], $seoMetaPlan['meta_description'], $seoMetaPlan['og_image_id']);
        }
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

    /**
     * @param array<int, array<string, mixed>> $plan
     * @param ?array{meta_title: ?string, meta_description: ?string, og_image_id: ?int} $seoMetaPlan
     */
    private function report(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $asJson,
        int $postId,
        array $plan,
        ?array $seoMetaPlan,
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
                'seo_meta' => $seoMetaPlan,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $verb = $saved ? 'Saved' : '[dry-run] Would save';
        $count = count($plan) + ($seoMetaPlan !== null ? 1 : 0);
        $io->success("{$verb} {$count} item(s) on post {$postId}.");

        foreach ($plan as $item) {
            $io->section("{$item['field_id']} ({$item['type']})");
            $io->text(is_string($item['preview']) ? $item['preview'] : (string) json_encode($item['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($seoMetaPlan !== null) {
            $io->section('SEO meta');
            $io->text('Title: ' . ($seoMetaPlan['meta_title'] ?? '(unchanged)'));
            $io->text('Description: ' . ($seoMetaPlan['meta_description'] ?? '(unchanged)'));
            $io->text('OG image ID: ' . ($seoMetaPlan['og_image_id'] !== null ? (string) $seoMetaPlan['og_image_id'] : '(unchanged)'));
        }
    }
}
