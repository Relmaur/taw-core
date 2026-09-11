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

        // A post type that was never indexable was never indexed — nothing
        // to remove either, regardless of its current status. Without this
        // early return, every save of a post type outside the indexed set
        // (nav_menu_item, attachment, revision-adjacent internal types)
        // fell through to a pointless removePost() call — noisy at best,
        // and a hard failure wherever RAG storage isn't reachable (e.g. no
        // pdo_sqlite in the CLI's PHP binary), as surfaced by a routine
        // `wp taw nav menus` rebuild tearing down/recreating dozens of
        // nav_menu_item posts.
        if (!in_array($post->post_type, RagSettings::indexedPostTypes(), true)) {
            return;
        }

        $eligible = $post->post_status === 'publish' && $post->post_password === '';

        if ($eligible) {
            wp_schedule_single_event(time(), self::HOOK, [$postId]);
            return;
        }

        // Not (or no longer) eligible — unpublished, trashed, moved to
        // draft/private, or password-protected. A post that was indexed
        // while eligible must not stay searchable once it isn't; a plain
        // DB delete is cheap enough to run inline rather than via cron.
        (new IngestionPipeline(new LlmClient()))->removePost($postId);
    }

    public function onBeforeDeletePost(int $postId): void
    {
        // Same reasoning as onSavePost()'s early return — a post type
        // that's never indexable was never indexed, so there's nothing to
        // remove. get_post_type() is still safe to call here: before_delete_post
        // fires before the row is actually gone.
        if (!in_array(get_post_type($postId), RagSettings::indexedPostTypes(), true)) {
            return;
        }

        (new IngestionPipeline(new LlmClient()))->removePost($postId);
    }

    public function runIngest(int $postId): void
    {
        (new IngestionPipeline(new LlmClient()))->ingestPost($postId);
    }
}
