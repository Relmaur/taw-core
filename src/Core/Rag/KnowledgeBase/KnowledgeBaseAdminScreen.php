<?php

declare(strict_types=1);

namespace TAW\Core\Rag\KnowledgeBase;

use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\Storage;
use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings → TAW Chatbot → Knowledge Bases — upload/delete admin-attached
 * .sqlite knowledge bases. Modeled directly on
 * {@see \TAW\Core\Content\ContentAdminScreen}: an `admin-post.php` handler
 * per action, nonce + capability gated, same redirect-with-query-args
 * notice pattern.
 */
final class KnowledgeBaseAdminScreen
{
    private const CAP = 'manage_options';
    private const INGEST_HOOK = 'taw_rag_ingest_kb';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_taw_rag_kb_upload', [$this, 'handleUpload']);
        add_action('admin_post_taw_rag_kb_delete', [$this, 'handleDelete']);
        add_action(self::INGEST_HOOK, [$this, 'runIngest']);
    }

    public function runIngest(string $id): void
    {
        (new KnowledgeBaseIngestionPipeline(new LlmClient()))->ingest($id);
    }

    public function addPage(): void
    {
        add_submenu_page(
            'taw_rag',
            'Knowledge Bases',
            'Knowledge Bases',
            self::CAP,
            'taw-rag-knowledge-bases',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'taw-theme'));
        }

        $registry = new KnowledgeBaseRegistry();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Knowledge Bases', 'taw-theme'); ?></h1>
            <p class="description">
                <?php esc_html_e('Any .sqlite file uploaded here becomes searchable by the chatbot — every table and text column is chunked, embedded, and semantically searched. No particular schema is required.', 'taw-theme'); ?>
            </p>

            <?php $this->renderNotices(); ?>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Name', 'taw-theme'); ?></th>
                    <th><?php esc_html_e('Description', 'taw-theme'); ?></th>
                    <th><?php esc_html_e('Status', 'taw-theme'); ?></th>
                    <th><?php esc_html_e('Chunks', 'taw-theme'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($registry->all() as $kb): ?>
                    <tr>
                        <td><strong><?php echo esc_html($kb['name']); ?></strong></td>
                        <td><?php echo esc_html($kb['description']); ?></td>
                        <td><?php echo esc_html($kb['status']); ?></td>
                        <td><?php echo esc_html((string) $kb['chunk_count']); ?></td>
                        <td>
                            <?php if ($kb['id'] !== KnowledgeBaseRegistry::WP_CONTENT_ID): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this knowledge base?', 'taw-theme')); ?>');">
                                    <?php wp_nonce_field('taw_rag_kb_delete_' . $kb['id']); ?>
                                    <input type="hidden" name="action" value="taw_rag_kb_delete">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($kb['id']); ?>">
                                    <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'taw-theme'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Add Knowledge Base', 'taw-theme'); ?></h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('taw_rag_kb_upload'); ?>
                <input type="hidden" name="action" value="taw_rag_kb_upload">
                <table class="form-table">
                    <tr>
                        <th><label for="taw_rag_kb_name"><?php esc_html_e('Name', 'taw-theme'); ?></label></th>
                        <td><input type="text" id="taw_rag_kb_name" name="name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="taw_rag_kb_description"><?php esc_html_e('Description', 'taw-theme'); ?></label></th>
                        <td>
                            <textarea id="taw_rag_kb_description" name="description" class="large-text" rows="2" required></textarea>
                            <p class="description"><?php esc_html_e('Tells the chatbot when to use this knowledge base — be specific about what it contains.', 'taw-theme'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="taw_rag_kb_file"><?php esc_html_e('.sqlite file', 'taw-theme'); ?></label></th>
                        <td><input type="file" id="taw_rag_kb_file" name="database" accept=".sqlite,.db,.sqlite3" required></td>
                    </tr>
                </table>
                <?php submit_button(__('Upload', 'taw-theme')); ?>
            </form>
        </div>
        <?php
    }

    /* -----------------------------------------------------------------
     * Handlers
     * ----------------------------------------------------------------- */

    public function handleUpload(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Unauthorized', 'taw-theme'));
        }
        check_admin_referer('taw_rag_kb_upload');

        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $description = sanitize_textarea_field((string) ($_POST['description'] ?? ''));

        if ($name === '' || $description === '') {
            $this->redirectBack(['taw_rag_error' => 'missing_fields']);
        }

        if (empty($_FILES['database']['tmp_name']) || !is_uploaded_file($_FILES['database']['tmp_name'])) {
            $this->redirectBack(['taw_rag_error' => 'no_file']);
        }

        $tmpPath = (string) $_FILES['database']['tmp_name'];
        if (!$this->looksLikeSqlite($tmpPath)) {
            $this->redirectBack(['taw_rag_error' => 'not_sqlite']);
        }

        Storage::ensureProtectedDir(Storage::dir());
        $id = KnowledgeBaseRegistry::generateId();
        $filename = 'kb-' . $id . '.sqlite';
        $destPath = Storage::dbPath($filename);

        if (!move_uploaded_file($tmpPath, $destPath)) {
            $this->redirectBack(['taw_rag_error' => 'upload_failed']);
        }

        (new KnowledgeBaseRegistry())->add($id, $name, $description, $filename);
        wp_schedule_single_event(time(), self::INGEST_HOOK, [$id]);

        $this->redirectBack(['taw_rag_uploaded' => 1]);
    }

    public function handleDelete(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Unauthorized', 'taw-theme'));
        }

        $id = sanitize_text_field((string) ($_POST['id'] ?? ''));
        check_admin_referer('taw_rag_kb_delete_' . $id);

        if ($id === KnowledgeBaseRegistry::WP_CONTENT_ID) {
            $this->redirectBack(['taw_rag_error' => 'cannot_delete_wp_content']);
        }

        $registry = new KnowledgeBaseRegistry();
        $kb = $registry->find($id);

        if ($kb !== null && $kb['source_file'] !== null) {
            @unlink(Storage::dbPath($kb['source_file']));
        }
        $registry->delete($id);

        $this->redirectBack(['taw_rag_deleted' => 1]);
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function looksLikeSqlite(string $path): bool
    {
        return ProtectedSqlite::looksLikeSqliteFile($path);
    }

    private function renderNotices(): void
    {
        $errors = [
            'missing_fields' => __('Name and description are required.', 'taw-theme'),
            'no_file' => __('No file was uploaded.', 'taw-theme'),
            'not_sqlite' => __('That file is not a valid SQLite database.', 'taw-theme'),
            'upload_failed' => __('The upload failed — please try again.', 'taw-theme'),
            'cannot_delete_wp_content' => __('The built-in "This site\'s content" knowledge base cannot be deleted.', 'taw-theme'),
        ];
        $err = sanitize_key((string) ($_GET['taw_rag_error'] ?? ''));
        if (isset($errors[$err])) {
            echo '<div class="notice notice-error"><p>' . esc_html($errors[$err]) . '</p></div>';
        }

        if (!empty($_GET['taw_rag_uploaded'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Uploaded — indexing runs in the background and may take a few minutes.', 'taw-theme') . '</p></div>';
        }

        if (!empty($_GET['taw_rag_deleted'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Knowledge base deleted.', 'taw-theme') . '</p></div>';
        }
    }

    /**
     * @param array<string, int|string> $args
     */
    private function redirectBack(array $args): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=taw-rag-knowledge-bases')));
        exit;
    }
}
