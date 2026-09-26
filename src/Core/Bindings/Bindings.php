<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `taw/field` Block Bindings source (ADR-0010): core blocks show TAW
 * fields.
 *
 *   <!-- wp:heading {"metadata":{"bindings":{"content":{"source":"taw/field","args":{"field":"book_subtitle"}}}}} -->
 *
 * Registered for every TAW theme by Boot::data() (one `init` hook).
 */
final class Bindings
{
    public const SOURCE = 'taw/field';

    private static bool $registered = false;

    private static ?ExpressionResolver $resolver = null;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('init', [self::class, 'registerSource']);
    }

    public static function registerSource(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        register_block_bindings_source(self::SOURCE, [
            'label'              => __('TAW field', 'taw-core'),
            'get_value_callback' => [self::class, 'getValue'],
            'uses_context'       => ['postId', 'postType'],
        ]);
    }

    /**
     * The source's get_value_callback.
     *
     * @param array<string, mixed> $args The binding's `args`.
     * @param object $block The WP_Block being rendered.
     */
    public static function getValue(array $args, object $block, string $attribute): mixed
    {
        $ref = Reference::fromArgs($args);
        if ($ref === null) {
            return null;
        }

        $name   = property_exists($block, 'name') && is_string($block->name) ? $block->name : '';
        $type   = property_exists($block, 'block_type') ? $block->block_type : null;
        $schema = is_object($type) && isset($type->attributes[$attribute]) && is_array($type->attributes[$attribute]) ? $type->attributes[$attribute] : null;

        return self::resolver()->resolve($ref, BindingContext::fromBlock($block), Target::for($name, $attribute, $schema));
    }

    public static function resolver(): ExpressionResolver
    {
        return self::$resolver ??= new FieldResolver();
    }

    /** @internal For tests. */
    public static function reset(): void
    {
        self::$registered = false;
        self::$resolver   = null;
    }
}
