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
use TAW\Helpers\Image;

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
 * Also extracts per-post SEO meta (meta title/description/social image) via
 * TAW\Core\Seo\SeoMeta — a separate 'seo_meta' key, not part of 'blocks',
 * since it isn't Metabox-field-shaped the same way (its source — TAW's own
 * fields vs. an active Yoast install's — can change between an extract and
 * a later inject, which SeoInjectCommand re-resolves fresh rather than
 * trusting what this dump recorded).
 *
 * --all extracts every published page/post in one run instead of a single
 * post_id, for a site-wide audit — output becomes {"posts": [...]}, one
 * dump per post in the same per-post shape as a single extraction.
 *
 * Boots WordPress (same pattern as InspectCommand/FieldsGetCommand) — the
 * field registry and post_meta only exist once WordPress is loaded.
 */
class SeoExtractCommand extends Command
{
    private const TEXT_TYPES = ['text', 'textarea', 'wysiwyg'];
    private const POST_TYPES = ['page', 'post'];

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
                with non-empty content, plus SEO meta (title/description/social image),
                and writes the result as JSON grouped by block — the same shape
                SeoInjectCommand expects back after editing.

                Examples:
                  <info>php bin/taw seo:extract 42</info>
                  <info>php bin/taw seo:extract 42 --output=.taw/seo-dump.json</info>
                  <info>php bin/taw seo:extract --all</info>
                  <info>php bin/taw seo:extract --all --output=.taw/seo-site-dump.json</info>
                HELP)
            ->addArgument('post_id', InputArgument::OPTIONAL, 'Post ID to extract copy from (omit when using --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Extract every published page/post instead of a single post_id')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'File path to write the JSON dump to (default: .taw/seo-dump.json, or .taw/seo-site-dump.json with --all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = (bool) $input->getOption('all');
        $postIdArg = $input->getArgument('post_id');

        if (!$all && $postIdArg === null) {
            $io->error('Provide a post_id, or pass --all to extract every published page/post.');
            return Command::FAILURE;
        }

        $outputPath = $input->getOption('output');
        $outputPath = is_string($outputPath) ? $outputPath : ($all ? '.taw/seo-site-dump.json' : '.taw/seo-dump.json');

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

        $dir = dirname($outputPath);
        if ($dir !== '.' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $io->error("Could not create output directory: {$dir}");
            return Command::FAILURE;
        }

        if ($all) {
            return $this->extractAll($io, $output, $outputPath);
        }

        $postId = (int) $postIdArg;
        $post = get_post($postId);
        if (!$post) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        $dump = $this->buildPostDump($post);

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

    private function extractAll(SymfonyStyle $io, OutputInterface $output, string $outputPath): int
    {
        $query = new \WP_Query([
            'post_type' => self::POST_TYPES,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $posts = [];
        foreach ($query->posts as $post) {
            $posts[] = $this->buildPostDump($post);
        }

        $json = (string) json_encode(['posts' => $posts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($outputPath, $json);

        $fieldCount = array_sum(array_map(
            static fn (array $dump) => array_sum(array_map(
                static fn (array $block) => count($block['fields']),
                $dump['blocks']
            )),
            $posts
        ));

        $io->success(sprintf(
            'Extracted %d field(s) across %d post(s) → %s',
            $fieldCount,
            count($posts),
            $outputPath
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{post_id: int, post_title: string, post_type: string, post_status: string, extracted_at: string, seo_meta: array<string, mixed>, blocks: array<int, array<string, mixed>>}
     */
    private function buildPostDump(\WP_Post $post): array
    {
        return [
            'post_id' => $post->ID,
            'post_title' => get_the_title($post),
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'extracted_at' => current_time('c'),
            'seo_meta' => $this->extractSeoMeta($post->ID),
            'blocks' => $this->extractBlocks($post->ID),
        ];
    }

    /**
     * @return array{source: string, meta_title: string, meta_description: string, og_image_id: int, og_image_url: string, featured_image_id: int, candidate_images: array<int, array<string, mixed>>}
     */
    private function extractSeoMeta(int $postId): array
    {
        $ogImageId = SeoMeta::ogImageId($postId);
        $featuredImageId = (int) get_post_thumbnail_id($postId);

        $candidates = [];
        if ($featuredImageId > 0) {
            $candidates[] = [
                'field_id' => '_featured_image',
                'label' => 'Featured Image',
                'block_id' => null,
                'attachment_id' => $featuredImageId,
                'url' => Image::url($featuredImageId, 'medium'),
            ];
        }

        foreach (Metabox::getFieldRegistry() as $fieldId => $field) {
            if (($field['type'] ?? '') !== 'image' || $fieldId === SeoMeta::OG_IMAGE_FIELD) {
                continue;
            }

            $prefix = $field['prefix'] ?? '_taw_';
            $attachmentId = (int) Metabox::get($postId, $fieldId, $prefix);
            if ($attachmentId <= 0) {
                continue;
            }

            $candidates[] = [
                'field_id' => $fieldId,
                'label' => $field['label'] ?? $fieldId,
                'block_id' => $field['block_id'] ?? null,
                'attachment_id' => $attachmentId,
                'url' => Image::url($attachmentId, 'medium'),
            ];
        }

        return [
            'source' => SeoMeta::targetMetaKeys()['source'],
            'meta_title' => SeoMeta::metaTitle($postId),
            'meta_description' => SeoMeta::metaDescription($postId),
            'og_image_id' => $ogImageId,
            'og_image_url' => $ogImageId > 0 ? Image::url($ogImageId, 'medium') : '',
            'featured_image_id' => $featuredImageId,
            'candidate_images' => $candidates,
        ];
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

        $seoMetaFieldIds = [SeoMeta::TITLE_FIELD, SeoMeta::DESCRIPTION_FIELD, SeoMeta::OG_IMAGE_FIELD];

        foreach (Metabox::getFieldRegistry() as $fieldId => $field) {
            if (in_array($fieldId, $seoMetaFieldIds, true)) {
                continue; // reported under 'seo_meta' instead, not 'blocks'
            }

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
