<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;

use TAW\Core\DataPanel\DataPanel;
use TAW\Core\I18n\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `taw/field` Block Bindings source (ADR-0010): core blocks show TAW
 * fields.
 *
 *   <!-- wp:heading {"metadata":{"bindings":{"content":{"source":"taw/field","args":{"field":"book_subtitle"}}}}} -->
 *
 * Registered for every TAW theme by Boot::data(): the source on `init`, the
 * preview route on `rest_api_init`, the editor script on
 * `enqueue_block_editor_assets`, and inline dynamic tags (InlineTags,
 * ADR-0011) on `render_block` / `register_block_type_args`.
 */
final class Bindings
{
    public const SOURCE = 'taw/field';

    public const SCRIPT_HANDLE = 'taw-bindings';

    /** Source entry in resources/data-panel/ (its manifest key). */
    public const SCRIPT_SOURCE = 'src/bindings/index.ts';

    public const SCRIPT_DEPS = ['react', 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-plugins', 'wp-components', 'wp-element', 'wp-hooks', 'wp-compose'];

    private static bool $registered = false;

    private static ?ExpressionResolver $resolver = null;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('init', [self::class, 'registerSource']);
        add_action('rest_api_init', [PreviewEndpoint::class, 'registerRoute']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditor']);
        InlineTags::register();
    }

    /**
     * The editor side (ADR-0010 decision 8): registers `taw/field` with
     * `getValues` (previews through PreviewEndpoint) and `getFieldsList`
     * (EditorFields, inlined). Post and site editor alike.
     */
    public static function enqueueEditor(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        DataPanel::assets()->script(self::SCRIPT_HANDLE, self::SCRIPT_SOURCE, self::SCRIPT_DEPS);

        $config = ['source' => self::SOURCE, 'route' => '/' . PreviewEndpoint::NAMESPACE . PreviewEndpoint::ROUTE, 'fields' => EditorFields::all()];
        $inline = sprintf('window.tawBindings = %s;', wp_json_encode($config));

        $localeData = Translations::scriptLocaleData();
        if ($localeData !== null) {
            $inline .= sprintf(' wp.i18n.setLocaleData(%s, %s);', wp_json_encode($localeData), wp_json_encode(Translations::DOMAIN));
        }
        wp_add_inline_script(self::SCRIPT_HANDLE, $inline, 'before');
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
        if (isset($args['expr'])) {
            return self::expressionValue($args['expr'], $block, $attribute);
        }

        $ref = Reference::fromArgs($args);
        // Property tags (post.date…) are inline-only for now (ADR-0011).
        if ($ref === null || $ref->tag !== null) {
            return null;
        }

        $name   = property_exists($block, 'name') && is_string($block->name) ? $block->name : '';
        $type   = property_exists($block, 'block_type') ? $block->block_type : null;
        $schema = is_object($type) && isset($type->attributes[$attribute]) && is_array($type->attributes[$attribute]) ? $type->attributes[$attribute] : null;

        return self::resolver()->resolve($ref, BindingContext::fromBlock($block), Target::for($name, $attribute, $schema));
    }

    /**
     * `args: {"expr": "…"}` (ADR-0012): an expression as a text attribute,
     * escaped for rich text, plain for HTML attributes (alt, title). Other
     * attributes (URLs, IDs) don't take expressions.
     */
    private static function expressionValue(mixed $expression, object $block, string $attribute): ?string
    {
        $name = property_exists($block, 'name') && is_string($block->name) ? $block->name : '';
        $kind = Target::for($name, $attribute)->kind;
        if (!is_string($expression) || !in_array($kind, ['text', 'plain'], true)) {
            return null;
        }

        $value = Evaluator::evaluate($expression, BindingContext::fromBlock($block))['value'];
        if ($value === '') {
            return null;
        }

        return $kind === 'text' ? esc_html($value) : $value;
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
        InlineTags::reset();
    }
}
