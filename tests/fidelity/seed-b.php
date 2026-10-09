<?php
/**
 * Site B before the import: some of A's records already here, older (the
 * usual pull). An existing page that A's version links to pages B doesn't
 * have yet, an existing term, the same file at the same path with other
 * metadata. `wp eval-file seed-b.php`, after seed.php's helpers aren't loaded.
 */

// No strict_types: `wp eval-file` evaluates this file.

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$home = wp_insert_post(['post_type' => 'page', 'post_name' => 'home', 'post_title' => 'Old home', 'post_status' => 'publish',
    'post_content' => "<!-- wp:paragraph -->\n<p>Old.</p>\n<!-- /wp:paragraph -->"], true);
$about = wp_insert_post(['post_type' => 'page', 'post_name' => 'about', 'post_title' => 'About (old)', 'post_status' => 'publish'], true);
wp_insert_term('Mystery', 'fid_genre', ['slug' => 'mystery', 'description' => 'Older']);
// A's `contact` but for one link, to services/team, which B gets from the import:
// planned before that page exists, it must still count as a change.
wp_insert_post(['post_type' => 'page', 'post_name' => 'contact', 'post_title' => 'Contact', 'post_status' => 'publish',
    'post_date' => '2025-01-01 10:00:00', 'post_date_gmt' => '2025-01-01 10:00:00'], true);

// photo.jpg at A's path: matched by path, its metadata updated, never downloaded.
$tmp = wp_tempnam('photo.jpg');
$img = imagecreatetruecolor(320, 200);
imagejpeg($img, $tmp, 80);
$upload = ['name' => 'photo.jpg', 'tmp_name' => $tmp, 'size' => filesize($tmp)];
$file = wp_handle_sideload($upload, ['test_form' => false], '2025/03');
$photo = wp_insert_attachment(['post_mime_type' => $file['type'], 'post_title' => 'Old photo', 'post_status' => 'inherit'], $file['file']);
wp_update_attachment_metadata($photo, wp_generate_attachment_metadata($photo, $file['file']));
update_post_meta($photo, '_wp_attachment_image_alt', 'Old alt');

printf("seeded B: home %d, about %d, photo %d\n", $home, $about, $photo);
