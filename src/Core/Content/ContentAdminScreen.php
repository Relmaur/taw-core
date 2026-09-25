<?php

declare(strict_types=1);

namespace TAW\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tools → **TAW Data** — the point-and-click front end for
 * {@see Exporter} / {@see Importer}.
 *
 *   - Export: one button, streams `taw-content-<host>-<Ymd-His>.json`.
 *   - Import: upload a snapshot / change-set → a **mandatory** dry-run
 *     review table (per-record, per-field: unchanged / changed / new /
 *     would-delete) → an explicit "Apply N changes" button with a
 *     conflict-policy selector. There is no one-click apply, and every
 *     apply writes a rollback snapshot first.
 *
 * Export needs the core `export` capability; import needs
 * `taw_import_content`, granted by a `user_has_cap` filter to anyone with
 * `manage_options` (override via `taw_import_content_cap`).
 */
final class ContentAdminScreen
{
    private const IMPORT_CAP = 'taw_import_content';
    private const PENDING_PREFIX = 'import-pending-';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_taw_content_export', [$this, 'handleExport']);
        add_action('admin_post_taw_content_import_preview', [$this, 'handleImportPreview']);
        add_action('admin_post_taw_content_import_apply', [$this, 'handleImportApply']);
        add_filter('user_has_cap', [$this, 'grantImportCap'], 10, 1);
    }

    /**
     * @param array<string, bool> $allcaps
     * @return array<string, bool>
     */
    public function grantImportCap(array $allcaps): array
    {
        $required = (string) apply_filters('taw_import_content_cap', 'manage_options');
        if (!empty($allcaps[$required])) {
            $allcaps[self::IMPORT_CAP] = true;
        }
        return $allcaps;
    }

    public function addPage(): void
    {
        add_management_page(
            'TAW Data',
            'TAW Data',
            'export',
            'taw-data',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('export')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'taw-core'));
        }

        $pending = $this->readPending();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('TAW Data', 'taw-core'); ?></h1>
            <p class="description">
                <?php esc_html_e('Portable content interchange — export a reviewable snapshot of this site\'s posts, TAW fields, options, terms and referenced media, or import one into it.', 'taw-core'); ?>
            </p>

            <?php $this->renderNotices(); ?>

            <h2><?php esc_html_e('Export', 'taw-core'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('taw_content_export'); ?>
                <input type="hidden" name="action" value="taw_content_export">
                <p>
                    <label><input type="checkbox" name="include_media" value="1" checked>
                        <?php esc_html_e('Include referenced media (URLs + metadata)', 'taw-core'); ?></label><br>
                    <label><input type="checkbox" name="all_media" value="1">
                        <?php esc_html_e('Include unreferenced media (orphans, widget images)', 'taw-core'); ?></label><br>
                    <label><input type="checkbox" name="include_drafts" value="1">
                        <?php esc_html_e('Include draft / pending posts', 'taw-core'); ?></label><br>
                    <label><input type="checkbox" name="with_users" value="1">
                        <?php esc_html_e('Include users (login, email, roles, profile — no passwords)', 'taw-core'); ?></label><br>
                    <label><input type="checkbox" name="with_settings" value="1">
                        <?php esc_html_e('Include environment settings (permalinks, timezone, sticky posts, …)', 'taw-core'); ?></label>
                </p>
                <details>
                    <summary><?php esc_html_e('Advanced', 'taw-core'); ?></summary>
                    <p class="description" style="color:#b32d2e">
                        <?php esc_html_e('These carry sensitive or bulky data — only for a deliberate full-site migration.', 'taw-core'); ?>
                    </p>
                    <p>
                        <label><input type="checkbox" name="with_user_passwords" value="1">
                            <?php esc_html_e('Include portable password hashes', 'taw-core'); ?></label><br>
                        <label><input type="checkbox" name="with_comments" value="1">
                            <?php esc_html_e('Include comments on exported posts', 'taw-core'); ?></label>
                    </p>
                </details>
                <?php submit_button(__('Download snapshot', 'taw-core'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Import', 'taw-core'); ?></h2>

            <?php if ($pending === null): ?>
                <?php if (!current_user_can(self::IMPORT_CAP)): ?>
                    <p><?php esc_html_e('You do not have permission to import content.', 'taw-core'); ?></p>
                <?php else: ?>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('taw_content_import_preview'); ?>
                        <input type="hidden" name="action" value="taw_content_import_preview">
                        <p>
                            <input type="file" name="snapshot" accept="application/json,.json" required>
                        </p>
                        <p class="description"><?php esc_html_e('A snapshot or change-set JSON file. The next screen shows exactly what would change — nothing is written yet.', 'taw-core'); ?></p>
                        <?php submit_button(__('Review changes', 'taw-core'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <?php $this->renderReviewTable($pending); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* -----------------------------------------------------------------
     * Handlers
     * ----------------------------------------------------------------- */

    public function handleExport(): void
    {
        if (!current_user_can('export')) {
            wp_die(esc_html__('Unauthorized', 'taw-core'));
        }
        check_admin_referer('taw_content_export');

        $scope = [
            'include_media'          => !empty($_POST['include_media']),
            'all_media'              => !empty($_POST['all_media']),
            'include_drafts'         => !empty($_POST['include_drafts']),
            'include_users'          => !empty($_POST['with_users']) || !empty($_POST['with_user_passwords']),
            'include_user_passwords' => !empty($_POST['with_user_passwords']),
            'include_comments'       => !empty($_POST['with_comments']),
            'include_settings'       => !empty($_POST['with_settings']),
        ];
        $snapshot = (new Exporter())->snapshot($scope);

        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site';
        $filename = 'taw-content-' . preg_replace('/[^a-z0-9.\-]/i', '-', (string) $host) . '-' . gmdate('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo (string) wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function handleImportPreview(): void
    {
        if (!current_user_can(self::IMPORT_CAP)) {
            wp_die(esc_html__('Unauthorized', 'taw-core'));
        }
        check_admin_referer('taw_content_import_preview');

        if (empty($_FILES['snapshot']['tmp_name']) || !is_uploaded_file($_FILES['snapshot']['tmp_name'])) {
            $this->redirectBack(['taw_error' => 'no_file']);
        }

        $raw = (string) file_get_contents($_FILES['snapshot']['tmp_name']); // phpcs:ignore
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $this->redirectBack(['taw_error' => 'bad_json']);
        }

        file_put_contents($this->pendingPath(), (string) wp_json_encode($data));
        $this->redirectBack([]);
    }

    public function handleImportApply(): void
    {
        if (!current_user_can(self::IMPORT_CAP)) {
            wp_die(esc_html__('Unauthorized', 'taw-core'));
        }
        check_admin_referer('taw_content_import_apply');

        $pending = $this->readPending();
        if ($pending === null) {
            $this->redirectBack(['taw_error' => 'expired']);
        }

        $policy = sanitize_text_field((string) ($_POST['policy'] ?? 'update'));

        $report = (new Importer())->apply($pending['data'], [
            'policy'           => $policy,
            'include_settings' => !empty($_POST['with_settings']),
        ]);

        @unlink($this->pendingPath());

        set_transient('taw_content_import_report_' . get_current_user_id(), $report, 300);
        $this->redirectBack(['imported' => 1]);
    }

    /* -----------------------------------------------------------------
     * Rendering
     * ----------------------------------------------------------------- */

    /**
     * @param array{data: array<string, mixed>, plan: array<string, mixed>} $pending
     */
    private function renderReviewTable(array $pending): void
    {
        $plan = $pending['plan'];
        $records = is_array($plan['records'] ?? null) ? $plan['records'] : [];

        $changeCount = 0;
        foreach ($records as $record) {
            if (!empty($record['changes']) || ($record['op'] ?? '') === 'would-delete') {
                $changeCount++;
            }
        }

        if (!empty($plan['registry_drift'])) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Registry drift', 'taw-core') . ':</strong></p><ul style="list-style:disc;margin-left:20px">';
            foreach ($plan['registry_drift'] as $note) {
                echo '<li>' . esc_html((string) $note) . '</li>';
            }
            echo '</ul></div>';
        }
        ?>
        <p><?php printf(esc_html__('%d of %d records would change. Nothing has been written.', 'taw-core'), (int) $changeCount, count($records)); ?></p>

        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Record', 'taw-core'); ?></th>
                <th><?php esc_html_e('Op', 'taw-core'); ?></th>
                <th><?php esc_html_e('Field-level changes', 'taw-core'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($records as $record): ?>
                <?php
                $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
                if ($changes === [] && ($record['op'] ?? '') !== 'would-delete') {
                    continue;
                }
                $label = ($record['kind'] ?? '') . ' · ' . ($record['type'] ?? $record['key'] ?? '') . ' · ' . ($record['slug'] ?? '');
                ?>
                <tr>
                    <td><code><?php echo esc_html($label); ?></code></td>
                    <td><?php echo esc_html((string) ($record['op'] ?? '')); ?></td>
                    <td>
                        <?php foreach ($changes as $field => $delta): ?>
                            <div><strong><?php echo esc_html((string) $field); ?></strong>:
                                <?php echo esc_html((string) ($delta['status'] ?? '')); ?>
                                <?php if (($delta['status'] ?? '') === 'changed'): ?>
                                    — <?php echo esc_html($this->truncate($delta['old'] ?? null)); ?>
                                    → <?php echo esc_html($this->truncate($delta['new'] ?? null)); ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em">
            <?php wp_nonce_field('taw_content_import_apply'); ?>
            <input type="hidden" name="action" value="taw_content_import_apply">
            <p>
                <label><?php esc_html_e('When a record already exists:', 'taw-core'); ?>
                    <select name="policy">
                        <option value="update"><?php esc_html_e('update it (overwrite)', 'taw-core'); ?></option>
                        <option value="create"><?php esc_html_e('skip it (only create new)', 'taw-core'); ?></option>
                        <option value="skip"><?php esc_html_e('skip everything (no writes)', 'taw-core'); ?></option>
                    </select>
                </label>
            </p>
            <p>
                <label><input type="checkbox" name="with_settings" value="1">
                    <?php esc_html_e('Also apply environment-settings options (permalinks, timezone, sticky posts, …)', 'taw-core'); ?></label>
            </p>
            <?php submit_button(sprintf(__('Apply %d changes', 'taw-core'), (int) $changeCount), 'primary', 'submit', false); ?>
            <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=taw-data&cancel=1')); ?>"><?php esc_html_e('Cancel', 'taw-core'); ?></a>
            <p class="description"><?php esc_html_e('A full rollback snapshot is written to wp-content/uploads/taw-private/ before anything changes.', 'taw-core'); ?></p>
        </form>
        <?php
    }

    private function renderNotices(): void
    {
        if (!empty($_GET['cancel'])) {
            @unlink($this->pendingPath());
        }

        $errors = [
            'no_file'  => __('No file was uploaded.', 'taw-core'),
            'bad_json' => __('That file is not valid JSON.', 'taw-core'),
            'expired'  => __('The pending import expired — please upload the file again.', 'taw-core'),
        ];
        $err = sanitize_key((string) ($_GET['taw_error'] ?? ''));
        if (isset($errors[$err])) {
            echo '<div class="notice notice-error"><p>' . esc_html($errors[$err]) . '</p></div>';
        }

        if (!empty($_GET['imported'])) {
            $report = get_transient('taw_content_import_report_' . get_current_user_id());
            delete_transient('taw_content_import_report_' . get_current_user_id());
            if (is_array($report)) {
                echo '<div class="notice notice-success"><p><strong>' . esc_html__('Import applied.', 'taw-core') . '</strong></p><ul style="list-style:disc;margin-left:20px">';
                foreach (['created', 'updated', 'skipped', 'deleted'] as $bucket) {
                    echo '<li>' . esc_html(ucfirst($bucket) . ': ' . count((array) ($report[$bucket] ?? []))) . '</li>';
                }
                echo '<li>' . esc_html__('Media sideloaded', 'taw-core') . ': ' . (int) ($report['media_sideloaded'] ?? 0) . '</li>';
                if (!empty($report['rollback_path'])) {
                    echo '<li>' . esc_html__('Rollback snapshot', 'taw-core') . ': <code>' . esc_html((string) $report['rollback_path']) . '</code></li>';
                }
                foreach ((array) ($report['warnings'] ?? []) as $w) {
                    echo '<li style="color:#b32d2e">' . esc_html((string) $w) . '</li>';
                }
                echo '</ul></div>';
            }
        }
    }

    /* -----------------------------------------------------------------
     * Pending-import storage
     * ----------------------------------------------------------------- */

    /**
     * @return array{data: array<string, mixed>, plan: array<string, mixed>}|null
     */
    private function readPending(): ?array
    {
        $path = $this->pendingPath();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        return ['data' => $data, 'plan' => (new Importer())->plan($data)];
    }

    private function pendingPath(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'taw-private';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir . '/' . self::PENDING_PREFIX . get_current_user_id() . '.json';
    }

    /**
     * @param array<string, int|string> $args
     */
    private function redirectBack(array $args): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php?page=taw-data')));
        exit;
    }

    private function truncate(mixed $value): string
    {
        $str = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
        return mb_strlen($str) > 60 ? mb_substr($str, 0, 57) . '…' : $str;
    }
}
