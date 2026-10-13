<?php
/**
 * The fidelity suite's publish round trip (taw/core 1.98): Local (site A)
 * publishes to production (site B), which changed things of its own since
 * the base. `wp --user=admin eval-file publish.php <command> …`:
 *
 *   baseline                        on A: the about page gets a text, a URL and a textarea (then B gets them too)
 *   local-edits                     on A: the about page's text and textarea change, a new image in f_image
 *   production-edits                on B: the about page's URL (only B) and textarea (both) change
 *   export <file>                   a snapshot with media hashes
 *   diff <base> <local> <out>       the change-set, with the base
 *   plan <change-set> <state>       Publish::plan(); checks the conflicts; keeps id + token in <state>
 *   stage <state> <uploads dir>     uploads every file the plan asks for, from A's uploads
 *   apply <state>                   approves every record, Local wins where both changed; checks B
 *   undo                            undoes the publish; checks B is back, B's own edit kept
 *
 * A's media can't be downloaded by then (the run stops serving A): the
 * files must travel as bytes.
 */

// No strict_types: `wp eval-file` evaluates this file.

use TAW\Core\Content\ChangeSet;
use TAW\Core\Content\Exporter;
use TAW\Core\Content\Publish;
use TAW\Core\Content\PublishError;
use TAW\Core\Metabox\Metabox;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/** @param list<string> $lines */
function pub_fail(string $what, array $lines = []): never
{
    fwrite(STDERR, "FAIL: {$what}\n" . implode('', array_map(static fn (string $l): string => "  {$l}\n", $lines)));
    exit(1);
}

/** @return array<string, mixed> */
function pub_read(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    is_array($data) || pub_fail("{$file} isn't JSON");

    return $data;
}

function pub_about(): int
{
    $page = get_page_by_path('about', OBJECT, 'page');
    $page instanceof WP_Post || pub_fail('no about page');

    return (int) $page->ID;
}

function pub_set(string $field, mixed $value): void
{
    $config = Metabox::fieldFor('post', 'page', $field) ?? pub_fail("no field {$field}");
    Metabox::writeMeta(pub_about(), $config, $value);
}

function pub_get(string $field): mixed
{
    $config = Metabox::fieldFor('post', 'page', $field) ?? pub_fail("no field {$field}");

    return get_post_meta(pub_about(), (string) $config['meta_key'], true);
}

/** @param array<string, mixed> $got */
function pub_expect(string $what, mixed $want, mixed $got): void
{
    if ($want !== $got) {
        pub_fail($what, ['want ' . json_encode($want, JSON_UNESCAPED_SLASHES), 'got  ' . json_encode($got, JSON_UNESCAPED_SLASHES)]);
    }
    echo "   ✓ {$what}\n";
}

/** @var list<string> $args the arguments after the file, set by `wp eval-file` */
$command = $args[0] ?? '';

switch ($command) {
    case 'baseline':
        pub_set('f_text', 'Text');
        pub_set('f_url', 'https://acme.example/');
        pub_set('f_textarea', 'Textarea');
        echo "baseline: f_text, f_url, f_textarea on about\n";
        break;

    case 'local-edits':
        pub_set('f_text', 'Text, edited in Local');
        pub_set('f_textarea', 'Textarea, edited in Local');
        $tmp = wp_tempnam('fresh.jpg');
        $img = imagecreatetruecolor(400, 240);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 160, 90));
        imagejpeg($img, $tmp, 85);
        $upload = ['name' => 'fresh.jpg', 'tmp_name' => $tmp, 'size' => filesize($tmp)];
        $file = wp_handle_sideload($upload, ['test_form' => false], '2026/09');
        isset($file['error']) && pub_fail('upload: ' . $file['error']);
        $id = wp_insert_attachment(['post_mime_type' => $file['type'], 'post_title' => 'Fresh', 'post_status' => 'inherit'], $file['file']);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file['file']));
        pub_set('f_image', $id);
        echo "local edits: f_text, f_textarea, f_image → fresh.jpg ({$id})\n";
        break;

    case 'production-edits':
        pub_set('f_url', 'https://client.example/');
        pub_set('f_textarea', 'Textarea, edited by the client');
        echo "production edits: f_url, f_textarea\n";
        break;

    case 'export':
        $snapshot = (new Exporter())->snapshot([
            'include_media' => true, 'all_media' => true, 'include_drafts' => true, 'include_users' => true,
            'include_settings' => true, 'include_options' => true, 'include_terms' => true, 'media_hashes' => true,
        ]);
        file_put_contents($args[1], json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo 'exported ', count($snapshot['posts'] ?? []), ' posts, ', count($snapshot['media'] ?? []), " media\n";
        break;

    case 'diff':
        $set = ChangeSet::between(pub_read($args[1]), pub_read($args[2]), ['with_base' => true]);
        file_put_contents($args[3], json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo 'change-set: ', count($set['operations']), " operations\n";
        break;

    case 'plan':
        $result = (new Publish())->plan(pub_read($args[1]));
        file_put_contents($args[2], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $about = null;
        $lines = [];
        foreach ($result['plan']['records'] as $record) {
            if (($record['record_key'] ?? '') === 'post:page:about') {
                $about = $record;
            }
            if (($record['kind'] ?? '') !== 'media') {
                $lines[] = $record['record_key'] . ' ' . implode(', ', array_keys($record['changes'] ?? []));
            }
        }
        $about !== null || pub_fail('the about page isn\'t in the plan', $lines);
        $c = $about['changes'];
        foreach (['fields.f_text', 'fields.f_url', 'fields.f_textarea', 'fields.f_image'] as $key) {
            isset($c[$key]) || pub_fail("{$key} isn't in the plan", array_keys($c));
        }
        pub_expect('f_text: only Local changed it', [false, true], [$c['fields.f_text']['conflict'], $c['fields.f_text']['local_changed']]);
        pub_expect('f_url: only production changed it', [true, false], [$c['fields.f_url']['conflict'], $c['fields.f_url']['local_changed']]);
        pub_expect('f_textarea: both changed it', [true, true], [$c['fields.f_textarea']['conflict'], $c['fields.f_textarea']['local_changed']]);
        pub_expect('the field says what it is', ['Text', 'text', 'Every field type'], [$c['fields.f_text']['label'], $c['fields.f_text']['type'], $c['fields.f_text']['metabox_title'] ?? null]);
        $needed = array_column($result['media']['needed'], 'file');
        sort($needed);
        // photo.jpg: B's file at that path has other bytes (seed-b), so A's goes up as a new file.
        pub_expect('the files B lacks are asked for, by their bytes', ['2025/03/photo.jpg', '2026/09/fresh.jpg'], $needed);
        echo '   records with changes: ', implode(' | ', $lines), "\n";
        break;

    case 'stage':
        $state = pub_read($args[1]);
        foreach ($state['media']['needed'] as $file) {
            $bytes = (string) file_get_contents(rtrim($args[2], '/') . '/' . $file['file']);
            $staged = (new Publish())->stage($state['publish_id'], $bytes);
            echo "staged {$staged['staged']['file']} ({$staged['staged']['bytes']} bytes), {$staged['remaining']} to go\n";
        }
        try {
            (new Publish())->stage($state['publish_id'], 'not a file the plan asked for');
            pub_fail('an unexpected file was accepted');
        } catch (PublishError $e) {
            pub_expect('an unexpected file is refused', 'publish_media_unexpected', $e->errorCode);
        }
        break;

    case 'apply':
        $state = pub_read($args[1]);
        $approved = [];
        $take = [];
        foreach ($state['plan']['records'] as $record) {
            if (($record['kind'] ?? '') === 'media') {
                continue;
            }
            $approved[] = ['key' => $record['record_key'], 'hash' => $record['hash']];
            foreach ($record['changes'] ?? [] as $key => $change) {
                if (($change['conflict'] ?? false) !== false && ($change['local_changed'] ?? true) === true) {
                    $take[$record['record_key']][] = $key; // Local wins where both changed
                }
            }
        }
        try {
            (new Publish())->apply($state['publish_id'], str_repeat('0', 32), $approved, [], $take);
            pub_fail('a wrong token was accepted');
        } catch (PublishError $e) {
            pub_expect('a wrong token is refused', 'publish_token_invalid', $e->errorCode);
        }
        $result = (new Publish())->apply($state['publish_id'], $state['token'], $approved, [], $take);
        ($result['report']['error'] ?? null) === null || pub_fail('import error: ' . $result['report']['error']);
        ($result['report']['failed'] ?? []) === [] || pub_fail('failed records', array_map('strval', $result['report']['failed']));

        pub_expect('f_text is Local\'s', 'Text, edited in Local', pub_get('f_text'));
        pub_expect('f_url stays the client\'s', 'https://client.example/', pub_get('f_url'));
        pub_expect('f_textarea is Local\'s (chosen)', 'Textarea, edited in Local', pub_get('f_textarea'));
        $image = (int) pub_get('f_image');
        $path = $image > 0 ? (string) wp_get_original_image_path($image) : '';
        $fresh = array_values(array_filter($state['media']['needed'], static fn (array $f): bool => $f['file'] === '2026/09/fresh.jpg'))[0] ?? [];
        pub_expect('f_image is fresh.jpg, copied byte for byte', $fresh['sha256'] ?? '?', $path !== '' && is_file($path) ? hash_file('sha256', $path) : '');
        try {
            (new Publish())->apply($state['publish_id'], $state['token'], $approved, [], $take);
            pub_fail('the same publish applied twice');
        } catch (PublishError $e) {
            pub_expect('the token works once', 'publish_token_invalid', $e->errorCode);
        }
        break;

    case 'undo':
        $preview = (new Publish())->undo('', true);
        pub_expect('the preview writes nothing', 'Text, edited in Local', pub_get('f_text'));
        $preview['restored'] > 0 || pub_fail('the preview has nothing to put back');
        (new Publish())->undo('', false);
        pub_expect('f_text is back', 'Text', pub_get('f_text'));
        pub_expect('f_textarea is back to the client\'s', 'Textarea, edited by the client', pub_get('f_textarea'));
        pub_expect('f_url, never touched, stays', 'https://client.example/', pub_get('f_url'));
        break;

    default:
        pub_fail("unknown command '{$command}'");
}
