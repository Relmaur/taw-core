<?php

declare(strict_types=1);

namespace TAW\Core\Content;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

/**
 * Saves the `defaults` that metaboxes and options pages declare to the
 * database, so content a theme only shows from code becomes records: it
 * can be edited in wp-admin, exported and pulled.
 *
 * Only empty fields are written, on the posts each metabox applies to and
 * on options pages. {@see self::apply()} writes a journal of exactly what
 * it wrote first, and {@see self::undo()} reverses those writes, leaving
 * any field edited since alone.
 *
 * Front ends: `bin/taw content:defaults` and Tools → TAW Data.
 */
final class Defaults
{
    private const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];
    private const JOURNAL_PATTERN = '/^defaults-\d{8}-\d{6}\.json$/';

    /**
     * Every empty field that has a default, and what saving would store
     * (`value`; `default` is the value as declared). `altered` marks a default the field's sanitizing changes (tags in a
     * text field, say): saving stores `value`, not what the page shows today.
     *
     * @return array{records: list<array{kind: string, id: int, label: string, source: string, field: string, key: string, value: mixed, default: mixed, altered: bool}>, posts: int, options: int, warnings: list<string>}
     */
    public function plan(): array
    {
        $records = [];
        $qualified = Metabox::getQualifiedRegistry();

        foreach (Metabox::instances() as $box) {
            $defaults = $box->defaults();
            if ($defaults === [] || ($types = $box->candidatePostTypes()) === []) {
                continue;
            }

            foreach ($this->posts($types) as $post) {
                if (!$box->appliesTo($post)) {
                    continue;
                }
                foreach (array_keys($defaults) as $fieldKey) {
                    $config = $qualified[$box->id() . '.' . $fieldKey] ?? null;
                    if ($config === null) {
                        continue;
                    }
                    $metaKey = Metabox::metaKeyOf($config);
                    $id = 'post:' . $post->ID . ':' . $metaKey;
                    if (isset($records[$id]) || !Metabox::isUnset(get_post_meta($post->ID, $metaKey, true), $config)) {
                        continue;
                    }

                    $records[$id] = $this->record('post', $post->ID, $this->postLabel($post), $box->id(), $fieldKey, $metaKey, $config, $defaults[$fieldKey], $box->storedDefault($fieldKey));
                }
            }
        }

        $options = OptionsPage::getFieldRegistry();
        foreach (OptionsPage::defaults() as $optionName => $default) {
            $config = $options[$optionName] ?? null;
            if ($config === null || !Metabox::isUnset(get_option($optionName, ''), $config)) {
                continue;
            }
            $records['option:' . $optionName] = $this->record('option', 0, (string) ($config['option_page_title'] ?? 'Options'), (string) ($config['option_page'] ?? ''), (string) ($config['id'] ?? $optionName), $optionName, $config, $default, OptionsPage::defaultOf($optionName));
        }

        $records = array_values($records);
        $warnings = [];
        foreach ($records as $record) {
            if ($record['altered']) {
                $warnings[] = sprintf("%s · %s: saving changes this default (the field's sanitizing alters it).", $record['label'], $record['field']);
            }
        }

        return [
            'records'  => $records,
            'posts'    => count(array_unique(array_column(array_filter($records, static fn (array $r): bool => $r['kind'] === 'post'), 'id'))),
            'options'  => count(array_filter($records, static fn (array $r): bool => $r['kind'] === 'option')),
            'warnings' => $warnings,
        ];
    }

    /**
     * Write what {@see self::plan()} lists. The journal is written before
     * anything else, and nothing is written when it can't be.
     *
     * @return array{written: int, posts: int, options: int, journal: ?string, warnings: list<string>, error: ?string}
     */
    public function apply(): array
    {
        $plan = $this->plan();
        $report = ['written' => 0, 'posts' => $plan['posts'], 'options' => $plan['options'], 'journal' => null, 'warnings' => $plan['warnings'], 'error' => null];
        if ($plan['records'] === []) {
            return $report;
        }

        $entries = [];
        foreach ($plan['records'] as $record) {
            $previous = $record['kind'] === 'post'
                ? get_post_meta($record['id'], $record['key'], true)
                : get_option($record['key'], null);
            $entries[] = [
                'kind'     => $record['kind'],
                'id'       => $record['id'],
                'key'      => $record['key'],
                'existed'  => $record['kind'] === 'post' ? metadata_exists('post', $record['id'], $record['key']) : $previous !== null,
                'previous' => $previous ?? '',
                'written'  => $record['value'],
            ];
        }

        $path = $this->journalDir() . '/defaults-' . gmdate('Ymd-His') . '.json';
        $json = (string) wp_json_encode(['created_gmt' => gmdate('Y-m-d H:i:s'), 'entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path, $json) === false) {
            $report['error'] = "Couldn't write the journal ({$path}), so nothing was saved.";
            return $report;
        }
        $report['journal'] = $path;

        $qualified = Metabox::getQualifiedRegistry();
        $options = OptionsPage::getFieldRegistry();
        foreach ($plan['records'] as $record) {
            if ($record['kind'] === 'post') {
                Metabox::writeMeta($record['id'], $qualified[$record['source'] . '.' . $record['field']], $record['default']);
            } else {
                OptionsPage::writeOption($options[$record['key']], $record['default']);
            }
            $report['written']++;
        }

        return $report;
    }

    /**
     * Reverse a journal's writes. A field whose value changed since is
     * kept. The journal is renamed so it can't be undone twice.
     *
     * @return array{restored: int, kept: int, error: ?string}
     */
    public function undo(string $journal): array
    {
        $path = $this->resolveJournal($journal);
        $data = $path !== null ? json_decode((string) file_get_contents($path), true) : null;
        if ($path === null || !is_array($data) || !is_array($data['entries'] ?? null)) {
            return ['restored' => 0, 'kept' => 0, 'error' => "That isn't a defaults journal in uploads/taw-private: {$journal}"];
        }

        $restored = 0;
        $kept = 0;
        foreach ($data['entries'] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $key = (string) ($entry['key'] ?? '');
            $isPost = ($entry['kind'] ?? '') === 'post';
            $current = $isPost ? get_post_meta($id, $key, true) : get_option($key, null);

            if (!self::same($current, $entry['written'] ?? null)) {
                $kept++;
                continue;
            }
            if (!empty($entry['existed'])) {
                $isPost ? update_post_meta($id, $key, wp_slash($entry['previous'] ?? '')) : update_option($key, $entry['previous'] ?? '');
            } else {
                $isPost ? delete_post_meta($id, $key) : delete_option($key);
            }
            $restored++;
        }

        rename($path, dirname($path) . '/undone-' . basename($path));

        return ['restored' => $restored, 'kept' => $kept, 'error' => null];
    }

    /**
     * The newest journal not yet undone.
     *
     * @return array{path: string, created_gmt: string, entries: int}|null
     */
    public function latestJournal(): ?array
    {
        $files = glob($this->journalDir() . '/defaults-*.json') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && is_array($data['entries'] ?? null)) {
                return ['path' => $file, 'created_gmt' => (string) ($data['created_gmt'] ?? ''), 'entries' => count($data['entries'])];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param mixed $default   As declared (a repeater's rows as an array).
     * @param mixed $displayed As the site shows it today (structured as JSON).
     * @return array{kind: string, id: int, label: string, source: string, field: string, key: string, value: mixed, default: mixed, altered: bool}
     */
    private function record(string $kind, int $id, string $label, string $source, string $field, string $key, array $config, mixed $default, mixed $displayed): array
    {
        $stored = Metabox::sanitizeForStorage($config, $default);

        return [
            'kind'    => $kind,
            'id'      => $id,
            'label'   => $label,
            'source'  => $source,
            'field'   => $field,
            'key'     => $key,
            'value'   => $stored,
            'default' => $default,
            'altered' => !self::same($stored, $displayed),
        ];
    }

    /**
     * @param string[] $types
     * @return list<\WP_Post>
     */
    private function posts(array $types): array
    {
        return get_posts([
            'post_type'        => $types,
            'post_status'      => self::STATUSES,
            'numberposts'      => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
    }

    private function postLabel(\WP_Post $post): string
    {
        $slug = $post->post_name !== '' ? $post->post_name : '#' . $post->ID;

        return $post->post_type . ' · ' . $slug;
    }

    /** Equal as stored: scalars as strings, JSON by its decoded value. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            if ((string) $a === (string) $b) {
                return true;
            }
            $da = json_decode((string) $a, true);
            $db = json_decode((string) $b, true);

            return is_array($da) && is_array($db) && $da == $db;
        }

        return $a == $b;
    }

    private function resolveJournal(string $journal): ?string
    {
        $dir = realpath($this->journalDir());
        $candidate = str_contains($journal, '/') ? $journal : $this->journalDir() . '/' . $journal;
        $path = realpath($candidate);
        if ($dir === false || $path === false || dirname($path) !== $dir || !preg_match(self::JOURNAL_PATTERN, basename($path))) {
            return null;
        }

        return $path;
    }

    private function journalDir(): string
    {
        $uploads = wp_upload_dir();
        $dir = rtrim((string) $uploads['basedir'], '/') . '/taw-private';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.php' => "<?php\n// Silence is golden.\n"] as $guard => $body) {
            if (!file_exists($dir . '/' . $guard)) {
                file_put_contents($dir . '/' . $guard, $body);
            }
        }

        return $dir;
    }
}
