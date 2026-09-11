<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Ingestion;

use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\RagSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hooks `save_post`/`before_delete_post` and dispatches indexing to
 * WP-Cron rather than running inline — an embeddings API call is external
 * network I/O with unpredictable latency, and blocking every editor's save
 * request on it (tight `max_execution_time` on shared hosting) is a worse
 * reliability trade than accepting wp-cron's "fires on next page view"
 * latency for a background indexing job. `content:reindex` is the manual
 * backfill/escape hatch when cron stalls on a low-traffic site.
 *
 * The first background-job pattern in taw-core — `wp_schedule_single_event`
 * already de-dupes identical pending events, so rapid successive saves of
 * the same post don't queue duplicate ingestion runs.
 */
final class PostIndexer
{
    private const HOOK = 'taw_rag_ingest_post';

    public function __construct()
    {
        add_action('save_post', [$this, 'onSavePost'], 20, 2);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost']);
        add_action(self::HOOK, [$this, 'runIngest']);
    }

    public function onSavePost(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, RagSettings::indexedPostTypes(), true)) {
            return;
        }

        wp_schedule_single_event(time(), self::HOOK, [$postId]);
    }

    public function onBeforeDeletePost(int $postId): void
    {
        (new IngestionPipeline(new LlmClient()))->removePost($postId);
    }

    public function runIngest(int $postId): void
    {
        (new IngestionPipeline(new LlmClient()))->ingestPost($postId);
    }
}
