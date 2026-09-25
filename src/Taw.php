<?php

declare(strict_types=1);

namespace TAW;

use TAW\Core\Fields\OptionFields;
use TAW\Core\Fields\PostFields;
use TAW\Core\Fields\TermFields;
use TAW\Core\Fields\UserFields;
use TAW\Core\Fields\Value;

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
 *   Taw::term($termId)->field('genre_tagline');
 *   Taw::user($userId)->field('author_links')->rows();
 *   echo Taw::option('company_phone');
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

    /**
     * A term's fields: an ID, a WP_Term, or null for the queried term (on a
     * category, tag or taxonomy archive).
     */
    public static function term(int|\WP_Term|false|null $term = null): TermFields
    {
        if ($term === null) {
            $queried = get_queried_object();

            return new TermFields($queried instanceof \WP_Term ? $queried : null);
        }
        if ($term === false || $term === 0) {
            return new TermFields(null);
        }

        $resolved = $term instanceof \WP_Term ? $term : get_term($term);

        return new TermFields($resolved instanceof \WP_Term ? $resolved : null);
    }

    /**
     * A user's fields: an ID or a WP_User.
     */
    public static function user(int|\WP_User|false $user): UserFields
    {
        if ($user === false || $user === 0) {
            return new UserFields(null);
        }

        $resolved = $user instanceof \WP_User ? $user : get_userdata($user);

        return new UserFields($resolved instanceof \WP_User ? $resolved : null);
    }

    /**
     * One options-page field, from whichever page registers it: an id
     * (`company_phone`) or an option name (`_taw_company_phone`).
     */
    public static function option(string $ref): Value
    {
        return self::options()->field($ref);
    }

    /**
     * Options-page fields: one page's (by its id), or every page's.
     */
    public static function options(?string $page = null): OptionFields
    {
        return new OptionFields($page);
    }
}
