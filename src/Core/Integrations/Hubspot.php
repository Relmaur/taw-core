<?php

declare(strict_types=1);

namespace TAW\Core\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HubSpot embedded-form renderer.
 *
 * Pairs with the `hubspot_form` Metabox/OptionsPage field type, which
 * stores `{"portal_id": "...", "form_id": "...", "region": "na1"}` as a
 * single JSON value (see {@see \TAW\Core\Metabox\Metabox::sanitizeHubspotFormValue()}).
 * No `enable()` gate — unlike Lucide's icon set, there's no bundled asset
 * this needs to opt into; the field type is available as soon as it's used
 * in a field definition, same as `post_select` or `files`.
 *
 * Usage:
 *   $config = Metabox::get($post->ID, 'contact_hubspot_form');
 *   echo Hubspot::render($config);
 *
 *   // Or fall back to the site's native TAW Form when unconfigured:
 *   if (Hubspot::isConfigured($config)) {
 *       echo Hubspot::render($config);
 *   } else {
 *       // render the native TAW\Core\Form\Form block instead
 *   }
 */
class Hubspot
{
    /**
     * Whether a hubspot_form value has both IDs a real embed needs.
     *
     * @param array<string, mixed>|string $value Decoded config, or the raw JSON string.
     */
    public static function isConfigured(array|string $value): bool
    {
        $config = self::decode($value);

        return $config['portal_id'] !== '' && $config['form_id'] !== '';
    }

    /**
     * Render the HubSpot forms-embed script for a hubspot_form field value.
     *
     * Prints HubSpot's own v2 embed loader (a plain, non-async `<script
     * src>`, so it downloads and executes before the following inline
     * `hbspt.forms.create()` call runs) targeting a freshly generated
     * container id — safe to call more than once per page, each call gets
     * its own container and its own loader/create pair.
     *
     * Returns an empty string (renders nothing) when the config is missing
     * a portal or form ID, so a template can safely do
     * `echo Hubspot::render($config) ?: '<!-- fallback -->'`-style branching
     * via {@see self::isConfigured()} instead.
     *
     * @param array<string, mixed>|string $value Decoded config, or the raw JSON string.
     */
    public static function render(array|string $value): string
    {
        $config = self::decode($value);

        if (!self::isConfigured($config)) {
            return '';
        }

        $targetId = 'taw-hubspot-form-' . substr(
            md5($config['portal_id'] . ':' . $config['form_id'] . ':' . $config['region']),
            0,
            10
        );

        return sprintf(
            '<div id="%1$s" class="taw-hubspot-form"></div>' .
                '<script src="https://js.hsforms.net/forms/embed/v2.js" charset="utf-8"></script>' .
                '<script>if (window.hbspt) { hbspt.forms.create({ region: "%2$s", portalId: "%3$s", formId: "%4$s", target: "#%1$s" }); }</script>',
            esc_attr($targetId),
            esc_js($config['region']),
            esc_js($config['portal_id']),
            esc_js($config['form_id'])
        );
    }

    /**
     * @param array<string, mixed>|string $value
     * @return array{portal_id: string, form_id: string, region: string}
     */
    private static function decode(array|string $value): array
    {
        $config = is_string($value) ? (json_decode($value, true) ?: []) : $value;
        if (!is_array($config)) {
            $config = [];
        }

        return [
            'portal_id' => (string) ($config['portal_id'] ?? ''),
            'form_id'   => (string) ($config['form_id'] ?? ''),
            'region'    => (string) ($config['region'] ?? '') ?: 'na1',
        ];
    }
}
