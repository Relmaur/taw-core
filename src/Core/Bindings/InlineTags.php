<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inline dynamic tags (ADR-0011): live values inside text.
 *
 *   <p>Published on <span class="taw-tag" data-taw-tag='{"tag":"post.date","format":"F j, Y"}'>September 26, 2026</span></p>
 *
 * The editor stores each tag as that span (the `taw/tag` format). Its text is
 * the value when it was inserted; on render it's replaced by the live value,
 * escaped, or the tag's `fallback`, or nothing. The span keeps its class (for
 * styling) and loses `data-taw-tag`.
 *
 * Properties (`tag`) resolve through TagResolver, fields (the `taw/field`
 * args) through the bindings resolver, so privacy, opt-outs and context are
 * the same as for Block Bindings. `{"expr": "…"}` holds an expression
 * (ADR-0012), evaluated by Expression\Evaluator from the same values.
 */
final class InlineTags
{
    public const CLASS_NAME = 'taw-tag';

    public const ATTRIBUTE = 'data-taw-tag';

    /** One tag: the opening span that carries data-taw-tag, its text, the closing span. */
    private const PATTERN = '/<span\b[^>]*\bdata-taw-tag\s*=[^>]*>.*?<\/span>/is';

    /** The attribute in the opening tag, double- or single-quoted (as the editor and kses write it). */
    private const ATTRIBUTE_VALUE = '/\s+data-taw-tag\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';

    private static ?TagResolver $tags = null;

    /**
     * How many values are resolving: blocks rendered meanwhile (an excerpt
     * renders content) are left alone. A depth, since an expression resolves
     * several values, one inside the other's evaluation.
     */
    private static int $resolving = 0;

    public static function register(): void
    {
        add_filter('render_block', [self::class, 'renderBlock'], 10, 3);
        add_filter('register_block_type_args', [self::class, 'addContext'], 10, 1);
    }

    /**
     * render_block: resolve every tag in the block's HTML.
     *
     * @param array<string, mixed> $parsed
     */
    public static function renderBlock(string $html, array $parsed = [], ?object $block = null): string
    {
        if (self::$resolving > 0 || !str_contains($html, self::ATTRIBUTE)) {
            return $html;
        }

        $context = $block !== null ? BindingContext::fromBlock($block) : BindingContext::fromBlock(new \stdClass());

        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => self::replace($match[0], $context),
            $html
        );
    }

    /**
     * register_block_type_args: blocks with rich text get the post context,
     * so a tag inside a Query Loop item reads that item's post. Only adds
     * context; it changes no output by itself.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function addContext(array $args): array
    {
        $attributes = $args['attributes'] ?? null;
        if (!is_array($attributes) || !str_contains((string) wp_json_encode($attributes), '"rich-text"')) {
            return $args;
        }

        $uses = is_array($args['uses_context'] ?? null) ? $args['uses_context'] : [];
        $args['uses_context'] = array_values(array_unique([...$uses, 'postId', 'postType']));

        return $args;
    }

    /**
     * A tag's value as plain, unescaped text: the resolved value, else the
     * fallback, else null. Shared with the editor preview.
     *
     * @param array<string, mixed> $args The decoded data-taw-tag.
     */
    public static function value(array $args, BindingContext $context): ?string
    {
        // A condition (ADR-0013): when it doesn't hold, the `else` text or nothing.
        if (array_key_exists('if', $args) && !Conditions::shown($args['if'], $context)) {
            return Conditions::otherwise($args);
        }

        $value = null;

        if (isset($args['expr'])) {
            // An expression (ADR-0012): its tokens come back through here.
            $value = is_string($args['expr']) ? Evaluator::evaluate($args['expr'], $context)['value'] : null;
        } elseif (($ref = Reference::fromArgs($args)) !== null) {
            self::$resolving++;
            try {
                $resolver = $ref->tag !== null ? (self::$tags ??= new TagResolver()) : Bindings::resolver();
                $value = $resolver->resolve($ref, $context, Target::inline());
            } finally {
                self::$resolving--;
            }
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        $fallback = $args['fallback'] ?? null;

        return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    private static function replace(string $span, BindingContext $context): string
    {
        $open = (string) strstr($span, '>', true) . '>';
        if (preg_match(self::ATTRIBUTE_VALUE, $open, $m) !== 1) {
            return $span;
        }

        $json = html_entity_decode(isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $args = json_decode($json, true);
        $text = is_array($args) ? self::value($args, $context) : null;

        return str_replace($m[0], '', $open) . esc_html($text ?? '') . '</span>';
    }

    /** @internal For tests. */
    public static function reset(): void
    {
        self::$tags = null;
        self::$resolving = 0;
    }
}
