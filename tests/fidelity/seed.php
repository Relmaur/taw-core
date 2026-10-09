<?php
/**
 * Seeds site A for the fidelity suite: `wp eval-file seed.php` with the
 * fixture theme active.
 *
 * Everything an import has to carry by natural key rather than by ID:
 * pages three levels deep (and two pages named `team` under different
 * parents), two `hero.jpg` in different month folders and a `-scaled`
 * upload, blocks holding attachment, post, term and reusable-block IDs,
 * every TAW field type (IDs inside groups, repeater rows and nested rows),
 * term fields, options-page fields, a wp-admin menu in a location, block
 * navigation, footnotes, the site icon and logo, front and posts pages,
 * sticky posts, a second author and a draft.
 *
 * Prints `seeded: <counts>` on success.
 */

// No strict_types: `wp eval-file` evaluates this file.

use TAW\Core\Metabox\Metabox;
use TAW\Core\Metabox\Store\TermMetaStore;
use TAW\Core\OptionsPage\OptionsPage;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// --- Helpers ---------------------------------------------------------------

/** An attachment from a generated file, uploaded into the given month folder. */
function fid_media(string $name, string $month, string $title, string $alt = '', int $width = 640): int
{
    $tmp = wp_tempnam($name);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    } else {
        $img = imagecreatetruecolor($width, (int) round($width * 0.6));
        imagefill($img, 0, 0, imagecolorallocate($img, crc32($name) % 255, 120, 200));
        $ext === 'png' ? imagepng($img, $tmp) : imagejpeg($img, $tmp, 80);
    }

    $upload = ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp)];
    $file = wp_handle_sideload($upload, ['test_form' => false], $month);
    if (isset($file['error'])) {
        throw new RuntimeException("upload {$name}: {$file['error']}");
    }
    $id = wp_insert_attachment(['post_mime_type' => $file['type'], 'post_title' => $title, 'post_status' => 'inherit',
        'post_excerpt' => "{$title} caption"], $file['file'], 0, true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file['file']));
    if ($alt !== '') {
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
    }

    return (int) $id;
}

/** @param array<string, mixed> $args */
function fid_post(string $type, string $slug, string $title, array $args = []): int
{
    $id = wp_insert_post(['post_type' => $type, 'post_name' => $slug, 'post_title' => $title,
        'post_status' => 'publish'] + $args, true);
    if (is_wp_error($id)) {
        throw new RuntimeException("{$type} {$slug}: " . $id->get_error_message());
    }

    return (int) $id;
}

/** A TAW field on a post, written like the editor's save. */
function fid_field(int $postId, string $ref, mixed $value): void
{
    $config = Metabox::fieldFor('post', (string) get_post_type($postId), $ref)
        ?? throw new RuntimeException("no field {$ref} on " . get_post_type($postId));
    Metabox::writeMeta($postId, $config, $value);
}

function fid_term_field(int $termId, string $ref, mixed $value): void
{
    $config = Metabox::fieldFor('term', 'fid_genre', $ref) ?? throw new RuntimeException("no term field {$ref}");
    Metabox::writeTo(new TermMetaStore(), $termId, $config, $value);
}

function fid_option(string $ref, mixed $value): void
{
    $config = OptionsPage::getFieldConfig($ref) ?? throw new RuntimeException("no option field {$ref}");
    OptionsPage::writeOption($config, $value);
}

function fid_image_block(int $id): string
{
    $url = (string) wp_get_attachment_url($id);

    return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"{$url}\" alt=\"\" class=\"wp-image-{$id}\"/></figure>\n<!-- /wp:image -->";
}

// --- Users -----------------------------------------------------------------

$author = wp_insert_user(['user_login' => 'ana', 'user_email' => 'ana@fidelity.test', 'user_pass' => wp_generate_password(),
    'display_name' => 'Ana', 'role' => 'author']);
if (is_wp_error($author)) {
    throw new RuntimeException($author->get_error_message());
}

// --- Media -----------------------------------------------------------------

$heroOld = fid_media('hero.jpg', '2024/05', 'Hero, 2024', 'An old hero');
$heroNew = fid_media('hero.jpg', '2025/01', 'Hero, 2025', 'A new hero');
$wide    = fid_media('panorama.jpg', '2025/01', 'Panorama', 'Wide', 2700); // > 2560: stored as -scaled
$icon    = fid_media('icon.png', '2025/02', 'Site icon', '', 512);
$logo    = fid_media('logo.png', '2025/02', 'Logo', 'The logo', 400);
$guide   = fid_media('guide.pdf', '2025/03', 'Guide');
$photo   = fid_media('photo.jpg', '2025/03', 'Photo', 'A photo');

// --- Terms -----------------------------------------------------------------

$fiction = (int) wp_insert_term('Fiction', 'fid_genre', ['slug' => 'fiction'])['term_id'];
$mystery = (int) wp_insert_term('Mystery', 'fid_genre', ['slug' => 'mystery', 'parent' => $fiction])['term_id'];
$noir    = (int) wp_insert_term('Noir', 'fid_genre', ['slug' => 'noir', 'parent' => $mystery])['term_id'];
$news    = (int) wp_insert_term('News', 'category', ['slug' => 'news'])['term_id'];
wp_insert_term('Featured', 'post_tag', ['slug' => 'featured']);

// --- Pages: a tree three levels deep, two pages named "team" ---------------

$home     = fid_post('page', 'home', 'Home');
$blog     = fid_post('page', 'blog', 'Blog');
$about    = fid_post('page', 'about', 'About', ['menu_order' => 1]);
$team     = fid_post('page', 'team', 'Our team', ['post_parent' => $about]);
$leads    = fid_post('page', 'leads', 'Leads', ['post_parent' => $team]);
$services = fid_post('page', 'services', 'Services', ['menu_order' => 2]);
$svcTeam  = fid_post('page', 'team', 'Service team', ['post_parent' => $services]);

// --- Block content types: a reusable block and a block navigation menu ------

$pattern = fid_post('wp_block', 'call-to-action', 'Call to action', [
    'post_content' => "<!-- wp:paragraph -->\n<p>Write to us.</p>\n<!-- /wp:paragraph -->\n\n" . fid_image_block($photo),
]);
$nav = fid_post('wp_navigation', 'main-navigation', 'Main navigation', [
    'post_content' => "<!-- wp:navigation-link {\"label\":\"About\",\"type\":\"page\",\"id\":{$about},\"url\":\"" . get_permalink($about) . "\",\"kind\":\"post-type\"} /-->\n"
        . "<!-- wp:navigation-link {\"label\":\"Mystery\",\"type\":\"fid_genre\",\"id\":{$mystery},\"url\":\"" . get_term_link($mystery) . "\",\"kind\":\"taxonomy\"} /-->",
]);

// --- Books and posts ---------------------------------------------------------

$book = fid_post('fid_book', 'the-long-night', 'The Long Night', [
    'post_author'  => $author,
    'post_content' => fid_image_block($heroNew),
    'tax_input'    => ['fid_genre' => [$noir]],
]);
wp_set_object_terms($book, [$noir], 'fid_genre');
set_post_thumbnail($book, $heroOld);

$aboutUrl = get_permalink($team);
$story = fid_post('post', 'a-story', 'A story', [
    'post_author'  => $author,
    'post_content' => "<!-- wp:paragraph -->\n<p>Meet <a href=\"{$aboutUrl}\">the team</a>.<sup data-fn=\"fn-1\" class=\"fn\"><a href=\"#fn-1\" id=\"fn-1-link\">1</a></sup></p>\n<!-- /wp:paragraph -->\n\n"
        . fid_image_block($heroOld) . "\n\n"
        . "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">" . fid_image_block($heroNew) . fid_image_block($wide) . "</figure>\n<!-- /wp:gallery -->\n\n"
        . "<!-- wp:file {\"id\":{$guide},\"href\":\"" . wp_get_attachment_url($guide) . "\"} -->\n<div class=\"wp-block-file\"><a href=\"" . wp_get_attachment_url($guide) . "\">Guide</a></div>\n<!-- /wp:file -->\n\n"
        . "<!-- wp:block {\"ref\":{$pattern}} /-->\n\n"
        . "<!-- wp:page-list {\"rootPageID\":{$about}} /-->\n\n"
        . "<!-- wp:query {\"queryId\":1,\"query\":{\"perPage\":3,\"postType\":\"fid_book\",\"include\":[{$book}],\"taxQuery\":{\"fid_genre\":[{$mystery}]}}} -->\n<div class=\"wp-block-query\"></div>\n<!-- /wp:query -->\n\n"
        . "<!-- wp:footnotes /-->",
    'post_category' => [$news],
    'tags_input'    => ['featured'],
]);
update_post_meta($story, 'footnotes', wp_slash((string) wp_json_encode([['id' => 'fn-1', 'content' => 'See <a href="' . $aboutUrl . '">our team</a>.']])));
set_post_thumbnail($story, $wide);
wp_set_object_terms($story, [$mystery], 'fid_genre');

$sticky = fid_post('post', 'pinned', 'Pinned', ['post_content' => "<!-- wp:paragraph -->\n<p>Pinned.</p>\n<!-- /wp:paragraph -->", 'post_category' => [$news]]);
stick_post($sticky);

fid_post('post', '', 'A draft without a slug', ['post_status' => 'draft', 'post_content' => fid_image_block($photo)]);

// The home page: a navigation block, a cover and media-text.
wp_update_post(['ID' => $home, 'post_content' =>
    "<!-- wp:navigation {\"ref\":{$nav}} /-->\n\n"
    . "<!-- wp:cover {\"url\":\"" . wp_get_attachment_url($heroNew) . "\",\"id\":{$heroNew}} -->\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-{$heroNew}\" src=\"" . wp_get_attachment_url($heroNew) . "\"/><div class=\"wp-block-cover__inner-container\"></div></div>\n<!-- /wp:cover -->\n\n"
    . "<!-- wp:media-text {\"mediaId\":{$photo},\"mediaType\":\"image\"} -->\n<div class=\"wp-block-media-text\"><figure class=\"wp-block-media-text__media\"><img src=\"" . wp_get_attachment_url($photo) . "\" class=\"wp-image-{$photo} size-full\"/></figure><div class=\"wp-block-media-text__content\"></div></div>\n<!-- /wp:media-text -->",
]);

// --- Every field type, IDs at every depth ------------------------------------

$fields = [
    'f_text'     => 'Plain text',
    'f_url'      => get_permalink($services),
    'f_number'   => 42,
    'f_textarea' => "Two\nlines",
    'f_wysiwyg'  => 'Some <strong>rich</strong> text with <a href="' . get_permalink($leads) . '">a link</a>.',
    'f_select'   => 'b',
    'f_checkbox' => '1',
    'f_color'    => '#123456',
    'f_date'     => '2026-03-14',
    'f_range'    => 7,
    'f_image'    => $heroNew,
    'f_icon'     => 'star',
    'f_files'    => [$guide, $photo],
    'f_gradient' => [['text' => 'Hello ', 'highlighted' => false], ['text' => 'world', 'highlighted' => true]],
    'f_hubspot'  => ['portal_id' => '123', 'form_id' => 'abc', 'region' => 'na1'],
    'f_link'     => ['url' => get_permalink($team), 'label' => 'Team', 'new_tab' => true],
    'f_post'     => $svcTeam,
    'f_posts'    => [$book, $team, $story],
    'f_group_title' => 'Grouped',
    'f_group_image' => $heroOld,
    'f_group_page'  => $leads,
    'f_rows'     => [
        ['label' => 'First', 'image' => $heroOld, 'target' => $team, 'items' => [['name' => 'Guide', 'file' => [$guide]]]],
        ['label' => 'Second', 'image' => $wide, 'target' => $book, 'items' => [['name' => 'Photo', 'file' => [$photo]], ['name' => 'Both', 'file' => [$guide, $photo]]]],
    ],
];
foreach ([$home, $story, $book] as $postId) {
    foreach ($fields as $ref => $value) {
        fid_field($postId, $ref, $value);
    }
}

// A page whose only difference on site B is a link to a page B doesn't have yet.
$contact = fid_post('page', 'contact', 'Contact', ['post_date' => '2025-01-01 10:00:00', 'post_date_gmt' => '2025-01-01 10:00:00']);
fid_field($contact, 'f_post', $svcTeam);

fid_term_field($mystery, 'g_color', '#aa0000');
fid_term_field($mystery, 'g_image', $heroNew);
fid_term_field($mystery, 'g_page', $leads);

fid_option('o_text', 'Site-wide text');
fid_option('o_image', $logo);
fid_option('o_page', $svcTeam);
fid_option('o_rows', [['label' => 'One', 'target' => $team], ['label' => 'Two', 'target' => $story]]);

// --- Site options, icon, logo, menus -----------------------------------------

update_option('blogname', 'Fidelity A');
update_option('blogdescription', 'Seeded by the fidelity suite');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('page_for_posts', $blog);
update_option('site_icon', $icon);
set_theme_mod('custom_logo', $logo);

$menu = wp_create_nav_menu('Primary menu');
$aboutItem = wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'About', 'menu-item-object' => 'page', 'menu-item-object-id' => $about,
    'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Team', 'menu-item-object' => 'page', 'menu-item-object-id' => $team,
    'menu-item-type' => 'post_type', 'menu-item-parent-id' => $aboutItem, 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Mystery', 'menu-item-object' => 'fid_genre', 'menu-item-object-id' => $mystery,
    'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Elsewhere', 'menu-item-url' => 'https://example.org/', 'menu-item-type' => 'custom',
    'menu-item-status' => 'publish']);
set_theme_mod('nav_menu_locations', ['primary' => $menu]);

printf("seeded: %d posts, %d attachments, %d terms, 1 menu\n",
    count(get_posts(['post_type' => ['page', 'post', 'fid_book', 'wp_block', 'wp_navigation'], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'])),
    count(get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids'])),
    (int) wp_count_terms(['taxonomy' => ['fid_genre', 'category', 'post_tag'], 'hide_empty' => false]));
