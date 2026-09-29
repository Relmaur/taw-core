<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;
use TAW\Core\Loop\EditorData;
use TAW\Core\Loop\RowValues;

use TAW\Core\DataPanel\DataPanel;
use TAW\Core\I18n\Translations;
use TAW\Helpers\Framework;

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

    public const CANVAS_STYLE = 'taw-tags-canvas';

    /**
     * Chips, conditional chips (dashed), and conditional blocks (ADR-0013):
     * a dashed outline with a label, dimmed when hidden for the edited post.
     */
    private const CANVAS_CSS = '.taw-tag{background:rgba(56,88,233,.1);box-shadow:inset 0 0 0 1px rgba(56,88,233,.35);border-radius:3px;padding:0 .2em;white-space:nowrap;cursor:pointer}'
        . '.taw-tag[data-rich-text-format-boundary],.taw-tag:focus{background:rgba(56,88,233,.22)}'
        . '.taw-tag[data-taw-tag*=\'"if":\']{box-shadow:none;outline:1px dashed rgba(56,88,233,.8);outline-offset:-1px}'
        . '.taw-conditional{position:relative;outline:1px dashed rgba(56,88,233,.55);outline-offset:4px}'
        . '.taw-conditional::after{content:attr(data-taw-condition);position:absolute;top:-6px;right:0;transform:translateY(-100%);z-index:2;padding:0 6px;border-radius:2px;background:#3858e9;color:#fff;font:500 10px/16px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;letter-spacing:.02em;white-space:nowrap;pointer-events:none}'
        . '.taw-conditional.is-taw-hidden{opacity:.5}.taw-conditional.is-taw-hidden::after{background:#8a6100}'
        // The TAW Loop in the canvas (ADR-0014): setup, the editable first item, previews, "no items".
        . '.taw-loop-setup .components-placeholder__fieldset{flex-direction:column;align-items:stretch}'
        . '.taw-loop-setup__sources{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;width:100%}'
        . '.taw-loop-setup__designs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;width:100%;margin-bottom:8px}'
        . '.taw-loop-setup__source{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:12px;border:1px solid #dcdcde;border-radius:4px;background:#fff;color:#1e1e1e;text-align:left;cursor:pointer;font:inherit;font-size:13px;line-height:1.4}'
        . '.taw-loop-setup__source:hover,.taw-loop-setup__source:focus-visible{border-color:#3858e9;box-shadow:0 0 0 1px #3858e9;outline:none}'
        . '.taw-loop-setup__source .dashicon{color:#3858e9}.taw-loop-setup__source span{color:#757575;font-size:12px}'
        . '.taw-loop-setup__details{display:flex;flex-direction:column;align-items:flex-start;gap:12px;width:100%;max-width:440px}'
        . '.taw-loop-setup__details .components-base-control{width:100%}'
        . '.taw-loop-setup__none{display:flex;gap:6px;margin:0;color:#757575;font-size:13px}'
        . '.taw-loop-template{outline:1px dashed rgba(56,88,233,.6);outline-offset:4px}'
        . '.taw-loop-preview{pointer-events:none;opacity:.85}'
        . '.taw-loop-note{margin:.75em 0 0;color:#757575;font:12px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
        . '.taw-loop-empty-edit{margin-top:1em;padding:.75em 1em;border:1px dashed #c3c4c7;border-radius:2px}'
        . '.taw-loop-empty-edit::before{content:attr(data-label);display:block;margin-bottom:.4em;color:#757575;font:500 11px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-transform:uppercase;letter-spacing:.04em}'
        . '.taw-loop-pagination-edit{display:flex;flex-wrap:wrap;align-items:center;gap:.6em;margin-top:1em}'
        . '.taw-loop-pagination-edit em{margin-left:auto;color:#757575;font:11px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-style:normal}';

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
        add_action('enqueue_block_assets', [self::class, 'enqueueCanvasStyles']);
        InlineTags::register();
        BlockVisibility::register();
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

        $config = [
            'source'    => self::SOURCE,
            'route'     => '/' . PreviewEndpoint::NAMESPACE . PreviewEndpoint::ROUTE,
            'fields'    => EditorFields::all(),
            // The TAW Loop's editor (ADR-0014): its sources and item previews.
            'loop'      => EditorData::all(),
            'loopRoute' => '/' . PreviewEndpoint::NAMESPACE . PreviewEndpoint::LOOP_ROUTE,
        ];
        $inline = sprintf('window.tawBindings = %s;', wp_json_encode($config));

        $localeData = Translations::scriptLocaleData();
        if ($localeData !== null) {
            $inline .= sprintf(' wp.i18n.setLocaleData(%s, %s);', wp_json_encode($localeData), wp_json_encode(Translations::DOMAIN));
        }
        wp_add_inline_script(self::SCRIPT_HANDLE, $inline, 'before');
    }

    /**
     * enqueue_block_assets: the chip look of inline tags (ADR-0011/0012) in
     * the editor canvas. That hook also runs on the front end, where tags
     * are plain text: admin requests only.
     */
    public static function enqueueCanvasStyles(): void
    {
        if (!is_admin()) {
            return;
        }

        // Dashicons for the TAW Loop's setup in the canvas iframe (it doesn't load them by itself).
        wp_register_style(self::CANVAS_STYLE, false, ['dashicons'], Framework::version());
        wp_enqueue_style(self::CANVAS_STYLE);
        wp_add_inline_style(self::CANVAS_STYLE, self::CANVAS_CSS);
    }

    public static function registerSource(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        register_block_bindings_source(self::SOURCE, [
            'label'              => __('TAW field', 'taw-core'),
            'get_value_callback' => [self::class, 'getValue'],
            'uses_context'       => ['postId', 'postType', BindingContext::ITEM],
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
        // A condition (ADR-0013): when it doesn't hold, the `else` text (text attributes) or empty.
        if (array_key_exists('if', $args) && !Conditions::shown($args['if'], BindingContext::fromBlock($block))) {
            return self::otherwise($args, $block, $attribute);
        }

        if (isset($args['expr'])) {
            return self::expressionValue($args['expr'], $block, $attribute);
        }

        // A TAW Loop item's value (ADR-0014): {"row": "award"}, {"loop": "index"}.
        if (isset($args['row']) || isset($args['loop'])) {
            $name   = property_exists($block, 'name') && is_string($block->name) ? $block->name : '';
            $type   = property_exists($block, 'block_type') ? $block->block_type : null;
            $schema = is_object($type) && isset($type->attributes[$attribute]) && is_array($type->attributes[$attribute]) ? $type->attributes[$attribute] : null;

            return RowValues::forTarget($args, BindingContext::fromBlock($block), Target::for($name, $attribute, $schema));
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

    /**
     * @param array<string, mixed> $args
     */
    private static function otherwise(array $args, object $block, string $attribute): string
    {
        $name = property_exists($block, 'name') && is_string($block->name) ? $block->name : '';
        $kind = Target::for($name, $attribute)->kind;
        $else = in_array($kind, ['text', 'plain'], true) ? Conditions::otherwise($args) : null;

        return $else === null ? '' : ($kind === 'text' ? esc_html($else) : $else);
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
