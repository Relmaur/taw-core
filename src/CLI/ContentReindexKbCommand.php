<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseIngestionPipeline;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseRegistry;
use TAW\Core\Rag\Llm\LlmClient;

/**
 * `bin/taw content:reindex-kb <id>`
 *
 * Manual re-ingestion of one admin-uploaded RAG knowledge base — cron-stall
 * recovery, or after changing the embedding model. Mirrors `content:reindex`
 * for the WP-content pipeline; the WP-content knowledge base itself still
 * uses that command, not this one.
 */
class ContentReindexKbCommand extends Command
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
            ->setName('content:reindex-kb')
            ->setDescription('Manually re-run ingestion for one admin-uploaded RAG knowledge base')
            ->addArgument('id', InputArgument::REQUIRED, 'Knowledge base id (see Settings -> TAW Chatbot -> Knowledge Bases)');
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

        $id = (string) $input->getArgument('id');

        $registry = new KnowledgeBaseRegistry();
        if ($registry->find($id) === null) {
            $io->error("No knowledge base with id '{$id}'. See Settings -> TAW Chatbot -> Knowledge Bases.");
            return Command::FAILURE;
        }
        if ($id === KnowledgeBaseRegistry::WP_CONTENT_ID) {
            $io->error("'{$id}' is the built-in WP-content knowledge base — use content:reindex instead.");
            return Command::FAILURE;
        }

        $updated = (new KnowledgeBaseIngestionPipeline(new LlmClient()))->ingest($id);

        if ($updated !== null) {
            $io->success(sprintf('Reindexed. %d chunk(s).', $updated['chunk_count']));
            return Command::SUCCESS;
        }

        $io->error('Ingestion failed — check `php bin/taw log:tail` for details.');
        return Command::FAILURE;
    }
}
