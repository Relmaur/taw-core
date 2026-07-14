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
 * Extract every text-bearing Metabox field on a post into a clean,
 * hierarchical JSON dump — the read half of an SEO/copy audit loop
 * (paired with SeoInjectCommand for writing rewrites back).
 *
 * Walks the same live field registry TAW\Core\Metabox\SeoContentIntegration
 * already walks to feed Yoast/SmartCrawl (Metabox::getFieldRegistry(),
 * recursing into repeater rows via their own 'fields' sub-schema) — that
 * class concatenates everything to an HTML string for a plugin to analyse;
 * this one keeps the block/field structure and drops anything that isn't
 * copy an agent would rewrite (image/url/files/post_select fields, and any
 * field with empty content), to keep the dump small and directly
 * round-trippable through SeoInjectCommand.
 *
 * Only text/textarea/wysiwyg fields are included — deliberately narrower
 * than SeoContentIntegration's HANDLED_TYPES, since this command's job is
 * "what copy could be rewritten," not "everything an SEO plugin might want
 * to see" (a plugin cares about alt text and internal links too; a copy
 * rewrite doesn't touch either).
 *
 * Boots WordPress (same pattern as InspectCommand/FieldsGetCommand) — the
 * field registry and post_meta only exist once WordPress is loaded.
 */
class SeoExtractCommand extends Command
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
            ->setName('seo:extract')
            ->setDescription('Extract a post\'s text-bearing Metabox field content into a hierarchical JSON dump for copy/SEO review')
            ->setHelp(<<<'HELP'
                Walks every registered Metabox field for the given post, keeps only
                text/textarea/wysiwyg fields (including inside repeaters, recursively)
                with non-empty content, and writes the result as JSON grouped by block —
                the same shape SeoInjectCommand expects back after editing.

                Examples:
                  <info>php bin/taw seo:extract 42</info>
                  <info>php bin/taw seo:extract 42 --output=.taw/seo-dump.json</info>
                HELP)
            ->addArgument('post_id', InputArgument::REQUIRED, 'Post ID to extract copy from')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'File path to write the JSON dump to', '.taw/seo-dump.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $postId = (int) $input->getArgument('post_id');
        $outputPath = (string) $input->getOption('output');

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

        $post = get_post($postId);
        if (!$post) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        $dump = [
            'post_id' => $postId,
            'post_title' => get_the_title($post),
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'extracted_at' => current_time('c'),
            'blocks' => $this->extractBlocks($postId),
        ];

        $dir = dirname($outputPath);
        if ($dir !== '.' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $io->error("Could not create output directory: {$dir}");
            return Command::FAILURE;
        }

        $json = (string) json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($outputPath, $json);

        $fieldCount = array_sum(array_map(
            static fn (array $block) => count($block['fields']),
            $dump['blocks']
        ));

        $io->success(sprintf(
            'Extracted %d field(s) across %d block(s) from post %d ("%s") → %s',
            $fieldCount,
            count($dump['blocks']),
            $postId,
            $dump['post_title'],
            $outputPath
        ));

        return Command::SUCCESS;
    }

    /**
     * Groups the flat field registry by block_id, keeping only fields with
     * non-empty text content for this specific post — a field being
     * registered doesn't mean this post's template actually populated it
     * (most block templates `if (empty($x)) return;`), so this naturally
     * excludes blocks that aren't used on this particular page.
     *
     * @return array<int, array{block_id: string, metabox_title: string, fields: array<int, array<string, mixed>>}>
     */
    private function extractBlocks(int $postId): array
    {
        $fieldsByBlock = [];
        $titleByBlock = [];

        foreach (Metabox::getFieldRegistry() as $fieldId => $field) {
            $type = $field['type'] ?? 'text';
            $blockId = $field['block_id'] ?? '(unassigned)';
            $prefix = $field['prefix'] ?? '_taw_';

            $titleByBlock[$blockId] ??= $field['metabox_title'] ?? $blockId;

            if ($type === 'repeater') {
                $rows = Metabox::get_repeater($postId, $fieldId, $prefix);
                $extractedRows = $this->extractRows($rows, $field['fields'] ?? []);

                if ($extractedRows === []) {
                    continue;
                }

                $fieldsByBlock[$blockId][] = [
                    'field_id' => $fieldId,
                    'label' => $field['label'] ?? $fieldId,
                    'type' => 'repeater',
                    'rows' => $extractedRows,
                ];
                continue;
            }

            if (!in_array($type, self::TEXT_TYPES, true)) {
                continue;
            }

            $value = (string) Metabox::get($postId, $fieldId, $prefix);
            if (trim($value) === '') {
                continue;
            }

            $fieldsByBlock[$blockId][] = [
                'field_id' => $fieldId,
                'label' => $field['label'] ?? $fieldId,
                'type' => $type,
                'value' => $value,
            ];
        }

        $blocks = [];
        foreach ($fieldsByBlock as $blockId => $fields) {
            $blocks[] = [
                'block_id' => $blockId,
                'metabox_title' => $titleByBlock[$blockId],
                'fields' => $fields,
            ];
        }

        return $blocks;
    }

    /**
     * Recursively filters repeater rows down to their text-like sub-fields
     * only, dropping rows that end up with no text content at all. Mirrors
     * TAW\Core\Metabox\SeoContentIntegration::repeaterRowsToHtml()'s
     * traversal, but builds a flat key→value row (or key→rows[] for a
     * nested repeater sub-field) instead of an HTML string.
     *
     * @param mixed[] $rows       Decoded rows — not guaranteed to be array
     *                            elements, since this is untrusted data
     *                            from json_decode()-ing stored post_meta.
     * @param array[] $subFields
     * @return array[]
     */
    private function extractRows(array $rows, array $subFields): array
    {
        $subFieldMap = [];
        foreach ($subFields as $sf) {
            if (!empty($sf['id'])) {
                $subFieldMap[$sf['id']] = $sf;
            }
        }

        $extracted = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $extractedRow = [];
            foreach ($row as $key => $value) {
                $sub = $subFieldMap[$key] ?? null;
                if ($sub === null) {
                    continue;
                }

                $type = $sub['type'] ?? 'text';

                if ($type === 'repeater') {
                    $nested = is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
                    $nestedRows = $this->extractRows($nested, $sub['fields'] ?? []);
                    if ($nestedRows !== []) {
                        $extractedRow[$key] = $nestedRows;
                    }
                    continue;
                }

                if (!in_array($type, self::TEXT_TYPES, true)) {
                    continue;
                }

                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $extractedRow[$key] = $value;
            }

            if ($extractedRow !== []) {
                $extracted[] = $extractedRow;
            }
        }

        return $extracted;
    }
}
