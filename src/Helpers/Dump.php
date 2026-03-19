<?php

declare(strict_types=1);

namespace TAW\Helpers;

class Dump
{
    private static array $queue  = [];
    private static bool  $hooked = false;

    // ── Public API ───────────────────────────────────────────────────────────────

    /**
     * Queue a value for display in the footer panel.
     * Only renders when WP_DEBUG is true.
     */
    public static function dump(mixed $value, string $label = ''): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) return;

        // Return if the user is on any admin screen
        if (is_admin()) return;

        self::$queue[] = [
            'value' => $value,
            'label' => $label ?: ('Dump #' . (count(self::$queue) + 1)),
            'trace' => self::caller(),
        ];

        if (!self::$hooked) {
            add_action('wp_footer', [self::class, 'render_panel'], 9999);
            self::$hooked = true;
        }
    }

    /**
     * Dump a value and immediately stop execution.
     * Renders an inline panel without needing wp_footer.
     */
    public static function dd(mixed $value, string $label = ''): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) return;

        self::$queue[] = [
            'value' => $value,
            'label' => $label ?: 'dd()',
            'trace' => self::caller(),
        ];

        echo self::build_panel();
        exit;
    }

    // ── Rendering ────────────────────────────────────────────────────────────────

    public static function render_panel(): void
    {
        if (empty(self::$queue)) return;
        echo self::build_panel();
    }

    private static function build_panel(): string
    {
        $id    = 'taw-' . substr(md5(uniqid()), 0, 8);
        $items = implode('', array_map(
            fn($entry) => self::render_entry($entry),
            self::$queue
        ));

        ob_start(); ?>
        <style>
            #<?= $id ?> {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 999999;
                width: 540px;
                max-height: 75vh;
                background: #0d1117;
                color: #e6edf3;
                border-radius: 10px;
                box-shadow: 0 8px 40px rgba(0, 0, 0, .7);
                font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                font-size: 12px;
                line-height: 1.65;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                border: 1px solid #30363d;
            }

            #<?= $id ?> * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
                font-size: 10px;
            }

            #<?= $id ?> .taw-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 9px 14px;
                background: #161b22;
                border-bottom: 1px solid #30363d;
                flex-shrink: 0;
                user-select: none;
            }

            #<?= $id ?> .taw-title {
                font-weight: 700;
                font-size: 11px;
                letter-spacing: .06em;
                color: #58a6ff;
                text-transform: uppercase;
            }

            #<?= $id ?> .taw-count {
                background: #30363d;
                color: #8b949e;
                border-radius: 20px;
                font-size: 10px;
                padding: 1px 7px;
                margin-left: 8px;
            }

            #<?= $id ?> .taw-close {
                cursor: pointer;
                color: #8b949e;
                background: none;
                border: none;
                font-size: 15px;
                line-height: 1;
                padding: 2px 5px;
                border-radius: 4px;
            }

            #<?= $id ?> .taw-close:hover {
                color: #f85149;
                background: #21262d;
            }

            #<?= $id ?> .taw-body {
                overflow-y: auto;
                flex: 1;
            }

            #<?= $id ?> .taw-entry {
                padding: 10px 14px;
                border-bottom: 1px solid #21262d;
            }

            #<?= $id ?> .taw-entry:last-child {
                border-bottom: none;
            }

            #<?= $id ?> .taw-entry-head {
                display: flex;
                flex-direction: column;
                align-items: baseline;
                gap: 10px;
                margin-bottom: 5px;
            }

            #<?= $id ?> .taw-lbl {
                color: #f0883e;
                font-weight: 700;
                font-size: 11px;
            }

            #<?= $id ?> .taw-loc {
                color: #484f58;
                font-size: 10px;
            }

            #<?= $id ?> .taw-val {
                padding-left: 2px;
            }

            /* ── value types ── */
            #<?= $id ?> .v-s {
                color: #a5d6ff;
            }

            #<?= $id ?> .v-n {
                color: #79c0ff;
            }

            #<?= $id ?> .v-b {
                color: #ff7b72;
            }

            #<?= $id ?> .v-nl {
                color: #ff7b72;
                font-style: italic;
            }

            #<?= $id ?> .v-t {
                color: #8b949e;
                font-size: 10px;
            }

            #<?= $id ?> .v-k {
                color: #7ee787;
            }

            #<?= $id ?> .v-c {
                color: #d2a8ff;
            }

            #<?= $id ?> .v-ar {
                color: #8b949e;
            }

            /* ── collapsible nodes ── */
            #<?= $id ?> summary {
                cursor: pointer;
                list-style: none;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            #<?= $id ?> summary::-webkit-details-marker {
                display: none;
            }

            #<?= $id ?> .taw-arrow {
                font-size: 9px;
                color: #484f58;
                display: inline-block;
                width: 10px;
                transition: transform .15s;
            }

            #<?= $id ?> details[open] .taw-arrow {
                transform: rotate(90deg);
            }

            #<?= $id ?> .taw-kids {
                padding-left: 18px;
                border-left: 1px solid #21262d;
                margin-top: 3px;
            }

            #<?= $id ?> .taw-row {
                display: flex;
                align-items: baseline;
                gap: 6px;
                padding: 1px 0;
                flex-wrap: wrap;
            }
        </style>

        <div id="<?= $id ?>">
            <div class="taw-head">
                <span class="taw-title">
                    ⚡ TAW Dump
                    <span class="taw-count"><?= count(self::$queue) ?></span>
                </span>
                <button class="taw-close" onclick="document.getElementById('<?= $id ?>').remove()" title="Close">✕</button>
            </div>
            <div class="taw-body"><?= $items ?></div>
        </div>
    <?php
        return (string) ob_get_clean();
    }

    private static function render_entry(array $entry): string
    {
        ob_start(); ?>
        <div class="taw-entry">
            <div class="taw-entry-head">
                <span class="taw-lbl"><?= esc_html($entry['label']) ?></span>
                <span class="taw-loc"><?= esc_html($entry['trace']) ?></span>
            </div>
            <div class="taw-val"><?= self::format($entry['value']) ?></div>
        </div>
    <?php
        return (string) ob_get_clean();
    }

    // ── Value formatter ───────────────────────────────────────────────────────────

    private static function format(mixed $value, int $depth = 0): string
    {
        if ($depth > 12) {
            return '<span class="v-t">[max depth]</span>';
        }

        return match (true) {
            is_null($value)   => '<span class="v-nl">null</span>',
            is_bool($value)   => '<span class="v-b">' . ($value ? 'true' : 'false') . '</span>',
            is_int($value)    => '<span class="v-n">' . $value . '</span> <span class="v-t">int</span>',
            is_float($value)  => '<span class="v-n">' . $value . '</span> <span class="v-t">float</span>',
            is_string($value) => self::format_string($value),
            is_array($value)  => self::format_array($value, $depth),
            is_object($value) => self::format_object($value, $depth),
            default           => '<span class="v-t">' . esc_html(gettype($value)) . '</span>',
        };
    }

    private static function format_string(string $value): string
    {
        return '<span class="v-s">"' . esc_html($value) . '"</span> <span class="v-t">string(' . strlen($value) . ')</span>';
    }

    private static function format_array(array $arr, int $depth): string
    {
        $count = count($arr);

        if ($count === 0) {
            return '<span class="v-t">array(0)</span> <span class="v-ar">[]</span>';
        }

        ob_start(); ?>
        <details <?= $depth < 2 ? 'open' : '' ?>>
            <summary>
                <span class="taw-arrow">▶</span>
                <span class="v-t">array(<?= $count ?>)</span>
            </summary>
            <div class="taw-kids">
                <?php foreach ($arr as $k => $v): ?>
                    <div class="taw-row">
                        <span class="v-k"><?= esc_html((string) $k) ?></span>
                        <span class="v-ar">=&gt;</span>
                        <?= self::format($v, $depth + 1) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php
        return (string) ob_get_clean();
    }

    private static function format_object(object $obj, int $depth): string
    {
        $class = get_class($obj);
        $props = (array) $obj;
        $count = count($props);

        if ($count === 0) {
            return '<span class="v-c">' . esc_html($class) . '</span> <span class="v-ar">{}</span>';
        }

        ob_start(); ?>
        <details <?= $depth < 2 ? 'open' : '' ?>>
            <summary>
                <span class="taw-arrow">▶</span>
                <span class="v-c"><?= esc_html($class) ?></span>
                <span class="v-t">(<?= $count ?>)</span>
            </summary>
            <div class="taw-kids">
                <?php foreach ($props as $k => $v):
                    // Unmangle protected/private keys (\x00ClassName\x00propName)
                    $key = preg_replace('/^\x00[^\x00]*\x00/', '', $k);
                ?>
                    <div class="taw-row">
                        <span class="v-k"><?= esc_html((string) $key) ?></span>
                        <span class="v-ar">=&gt;</span>
                        <?= self::format($v, $depth + 1) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
<?php
        return (string) ob_get_clean();
    }

    // ── Utilities ─────────────────────────────────────────────────────────────────

    /** @var string[] Absolute paths of wrapper files to skip in stack traces. */
    private static array $skip_files = [];

    /**
     * Register a file to be ignored when resolving the caller location.
     * Add this one line at the top of any file that wraps dump()/dd():
     *
     *   \MLC\Helpers\Dump::skipFile(__FILE__);
     */
    public static function skipFile(string $file): void
    {
        $resolved = realpath($file);
        if ($resolved && !in_array($resolved, self::$skip_files, true)) {
            self::$skip_files[] = $resolved;
        }
    }

    private static function caller(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);

        $skip = array_merge([realpath(__FILE__)], self::$skip_files);

        foreach ($frames as $frame) {
            $file = realpath($frame['file'] ?? '') ?: '';
            if (!$file || in_array($file, $skip, true)) continue;
            $relative = str_replace(get_template_directory(), '', $frame['file']);
            return $relative . ':' . ($frame['line'] ?? '?');
        }

        return 'unknown';
    }
}
