<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post and site properties for inline dynamic tags (ADR-0011), the second
 * ExpressionResolver: `{"tag": "post.date", "format": "F j, Y"}`.
 *
 * Returns plain, unescaped text (the caller escapes it), or null for nothing.
 * The post is the block's context post (a Query Loop item, else the queried
 * post). As core does: a post that isn't publicly viewable needs `read_post`,
 * and a password-protected post gives only the properties its listing shows
 * anyway (id, title, url, date, type) until it's unlocked.
 */
final class TagResolver implements ExpressionResolver
{
    public const TAGS = [
        'post.id', 'post.title', 'post.date', 'post.modified', 'post.url', 'post.excerpt', 'post.author', 'post.type',
        'site.name', 'site.tagline', 'site.url', 'site.year',
    ];

    /** What a password-protected post still shows. */
    private const PUBLIC_WHEN_PROTECTED = ['post.id', 'post.title', 'post.url', 'post.date', 'post.type'];

    public static function knows(string $tag): bool
    {
        return in_array($tag, self::TAGS, true);
    }

    public function resolve(Reference $ref, BindingContext $context, Target $target): ?string
    {
        $tag = $ref->tag;
        if ($tag === null || !self::knows($tag)) {
            return null;
        }

        $text = str_starts_with($tag, 'site.') ? self::site($tag) : self::post($tag, $context->postId, $ref->format);

        return $text === null || trim($text) === '' ? null : trim($text);
    }

    private static function site(string $tag): string
    {
        return match ($tag) {
            'site.name'    => self::plain(get_bloginfo('name')),
            'site.tagline' => self::plain(get_bloginfo('description')),
            'site.url'     => home_url('/'),
            default        => (string) wp_date('Y'),
        };
    }

    private static function post(string $tag, int $postId, ?string $format): ?string
    {
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof \WP_Post || (!is_post_publicly_viewable($post) && !current_user_can('read_post', $post->ID))) {
            return null;
        }
        if (post_password_required($post) && !in_array($tag, self::PUBLIC_WHEN_PROTECTED, true)) {
            return null;
        }

        $format ??= (string) get_option('date_format');

        return match ($tag) {
            'post.id'       => (string) $post->ID,
            'post.title'    => self::plain(get_the_title($post)),
            'post.date'     => (string) get_the_date($format, $post),
            'post.modified' => (string) get_the_modified_date($format, $post),
            'post.url'      => (string) get_permalink($post),
            'post.excerpt'  => self::plain(get_the_excerpt($post)),
            'post.author'   => self::plain((string) get_the_author_meta('display_name', (int) $post->post_author)),
            default         => self::plain((string) (get_post_type_object($post->post_type)->labels->singular_name ?? '')),
        };
    }

    /** Text as a reader sees it: no tags, entities decoded (the caller escapes it once). */
    private static function plain(string $html): string
    {
        return html_entity_decode(wp_strip_all_tags($html, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
