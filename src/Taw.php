<?php

declare(strict_types=1);

namespace TAW;

use TAW\Core\Fields\PostFields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads of TAW fields (ADR-0009):
 *
 *   $book = Taw::post();                          // the current post
 *   echo $book->field('book_subtitle');            // escaped
 *   $book->field('book_cover')->image()->url('large');
 *   foreach ($book->field('book_awards')->rows() as $row) { echo $row->field('name'); }
 *
 * Nothing throws: a missing post or field reads as empty.
 */
final class Taw
{
    /**
     * A post's fields: an ID, a WP_Post, or null for the current post
     * (`false`, as MetaBlock passes on a 404, reads as no post).
     */
    public static function post(int|\WP_Post|false|null $post = null): PostFields
    {
        if ($post === false || $post === 0) {
            return new PostFields(null);
        }

        $resolved = get_post($post);

        return new PostFields($resolved instanceof \WP_Post ? $resolved : null);
    }
}
