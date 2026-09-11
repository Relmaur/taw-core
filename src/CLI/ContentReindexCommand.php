<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Rag\Ingestion\IngestionPipeline;
use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\RagSettings;

/**
 * `bin/taw content:reindex [--post-type=post,page] [--batch=20] [--yes]`
 *
 * Manual backfill for the RAG unstructured-archive vector index — runs
 * {@see IngestionPipeline::ingestPost()} synchronously against every
 * published post of the given type(s), with a progress bar. Also the
 * fastest way to exercise the ingestion pipeline end-to-end without
 * waiting on wp-cron (see {@see \TAW\Core\Rag\Ingestion\PostIndexer}).
 */
class ContentReindexCommand extends Command
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
            ->setName('content:reindex')
            ->setDescription('Backfill/refresh the RAG unstructured-archive vector index')
            ->addOption('post-type', null, InputOption::VALUE_REQUIRED, 'Comma-separated post type slugs (default: the configured indexed post types)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Posts per batch', '20')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');
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

        $postTypesOption = $input->getOption('post-type');
        $postTypes = is_string($postTypesOption) && $postTypesOption !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $postTypesOption))))
            : RagSettings::indexedPostTypes();

        if ($postTypes === []) {
            $io->error('No post types to index — configure them under Settings -> TAW Chatbot, or pass --post-type.');
            return Command::FAILURE;
        }

        $batchSize = max(1, (int) $input->getOption('batch'));

        if (!$input->getOption('yes') && !$io->confirm(
            'Reindex all published posts of type(s) ' . implode(', ', $postTypes) . '?',
            false
        )) {
            $io->note('Cancelled.');
            return Command::SUCCESS;
        }

        $ids = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if ($ids === []) {
            $io->note('No matching posts found.');
            return Command::SUCCESS;
        }

        $pipeline = new IngestionPipeline(new LlmClient());
        $progress = $io->createProgressBar(count($ids));
        $progress->start();

        $failures = 0;
        foreach (array_chunk($ids, $batchSize) as $batch) {
            foreach ($batch as $postId) {
                try {
                    $pipeline->ingestPost((int) $postId);
                } catch (\Throwable) {
                    $failures++;
                }
                $progress->advance();
            }
        }

        $progress->finish();
        $io->newLine(2);

        if ($failures > 0) {
            $io->warning("{$failures} post(s) failed to index — check `php bin/taw log:tail` for details.");
        }

        $io->success(sprintf('Indexed %d post(s).', count($ids) - $failures));

        return Command::SUCCESS;
    }
}
