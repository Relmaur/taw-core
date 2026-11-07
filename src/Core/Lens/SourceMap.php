<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

// No ABSPATH guard: pure definitions with no include-time side effects (the WordPress calls run only once
// the map is switched on, under the Lens gate).

/**
 * TAW Lens's source map (umbrella ADR-0006 § 7): what rendered each part of the page, so Lens can tell a
 * person, and Claude, which field, template text or block an element came from instead of guessing.
 *
 * Off unless the Lens gate is open (ClassicAdapter::start()); while off, every method here returns at once
 * and prints nothing, so the page is byte-identical. While on:
 *
 * - **Nodes.** The main template is node 0. Every block render and template part opens a node, printing
 *   `<!--taw-lens:N-->` before its output and `<!--/taw-lens:N-->` after (comments: no layout effect).
 * - **Reads.** Every value read while a node is open is recorded on it: TAW fields and options (with the
 *   field's default when that's what was shown), the site title and tagline, post titles, menu items.
 * - **Template text.** Each node's template file is scanned for the text it writes (TemplateText).
 * - **Manifest.** One `<script type="application/json" id="taw-lens-map">` in the footer, which the page
 *   script reads to build the inspect card, the structure tree and, later, what Claude receives.
 *
 * The classic adapter (ClassicAdapter) feeds it from WordPress's hooks; MetaBlock and Block open their own
 * nodes. A block-theme adapter would feed the same nodes from `render_block`.
 */
final class SourceMap
{
    /** The manifest's shape; the page script refuses one it doesn't know. */
    public const VERSION = 1;

    public const MANIFEST_ID = 'taw-lens-map';

    private static bool $on = false;

    /** @var array<int, array<string, mixed>> */
    private static array $nodes = [];

    /** @var list<int> */
    private static array $stack = [0];

    private static int $next = 0;

    /** While above 0, reads aren't recorded (Lens's own lookups, e.g. a field's default). */
    private static int $quiet = 0;

    public static function on(): bool
    {
        return self::$on;
    }

    /** Starts recording. The root node exists from here, so reads before the template is known land on it. */
    public static function enable(): void
    {
        self::$on = true;
        self::$nodes[0] ??= ['id' => 0, 'kind' => 'page', 'name' => '', 'label' => '', 'parent' => null, 'template' => null, 'reads' => []];
    }

    /** The main template: the root node, the whole document. */
    public static function root(string $template, string $label = ''): void
    {
        if (!self::$on) {
            return;
        }
        self::$nodes[0]['name'] = basename($template);
        self::$nodes[0]['template'] = $template;
        if ($label !== '') {
            self::$nodes[0]['label'] = $label;
        }
    }

    public static function setRootLabel(string $label): void
    {
        if (self::$on) {
            self::$nodes[0]['label'] = $label;
        }
    }

    /**
     * Opens a node and prints its start marker.
     *
     * @param 'part'|'block' $kind
     * @param array<string, mixed> $extra
     * @return int|null The node id, for close(); null while the map is off.
     */
    public static function open(string $kind, string $name, string $label, ?string $template, array $extra = []): ?int
    {
        if (!self::$on) {
            return null;
        }
        $id = ++self::$next;
        self::$nodes[$id] = ['id' => $id, 'kind' => $kind, 'name' => $name, 'label' => $label, 'parent' => self::current(), 'template' => $template]
            + $extra + ['reads' => []];
        self::$stack[] = $id;
        echo '<!--taw-lens:' . $id . '-->';

        return $id;
    }

    /**
     * Closes a node (and any it left open) and prints its end marker. Never closes the root.
     */
    public static function close(?int $id): void
    {
        if ($id === null || !self::$on || !in_array($id, self::$stack, true)) {
            return;
        }
        while (count(self::$stack) > 1) {
            $top = array_pop(self::$stack);
            echo '<!--/taw-lens:' . $top . '-->';
            if ($top === $id) {
                return;
            }
        }
    }

    /**
     * Opens a node for a TAW block (MetaBlock or Block) about to render: its id, its class file, the template
     * it renders (index.php) and every file in its folder (the block card lists them).
     *
     * @return int|null The node id, for close(); null while the map is off.
     */
    public static function openBlock(object $block, ?int $postId = null): ?int
    {
        if (!self::$on) {
            return null;
        }
        $class = new \ReflectionClass($block);
        $classFile = (string) $class->getFileName();
        $dir = dirname($classFile);
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && !str_starts_with(basename($file), '.')) {
                $files[] = $file;
            }
        }
        usort($files, static fn (string $a, string $b): int => ($a === $classFile ? -1 : ($b === $classFile ? 1 : strcmp($a, $b))));
        $id = method_exists($block, 'getId') ? (string) $block->getId() : $class->getShortName();

        return self::open('block', $id, self::humanize($class->getShortName()), $dir . '/index.php', [
            'class' => $class->getName(),
            'files' => $files,
            'post'  => $postId,
        ]);
    }

    /**
     * Records a value read while the current node is open. Read again, the latest value wins.
     *
     * @param array{store: string, object: int|string, key: string, type: string, label: string, value: mixed, admin: string}&array<string, mixed> $read
     */
    public static function record(array $read): void
    {
        if (!self::$on || self::$quiet > 0) {
            return;
        }
        $node = self::current();
        self::$nodes[$node]['reads'][$read['store'] . ':' . $read['object'] . ':' . $read['key']] = $read;
    }

    /**
     * Runs $fn without recording what it reads.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function quietly(callable $fn): mixed
    {
        self::$quiet++;
        try {
            return $fn();
        } finally {
            self::$quiet--;
        }
    }

    /**
     * The manifest: every node with its reads, and every template file with its text and the source lines
     * around it. Paths are relative to $themeDir when inside it; `abs` keeps the real path for "Open in editor".
     *
     * @return array{version: int, nodes: array<int, array<string, mixed>>, files: array<string, array<string, mixed>>}
     */
    public static function manifest(string $themeDir): array
    {
        $files = [];
        $path = static function (?string $file) use ($themeDir, &$files): ?string {
            if ($file === null || $file === '' || !is_file($file)) {
                return null;
            }
            $real = realpath($file) ?: $file;
            $relative = self::relative($real, $themeDir);
            $files[$relative] ??= ['abs' => $real, 'scan' => false];

            return $relative;
        };

        $nodes = [];
        foreach (self::$nodes as $id => $node) {
            $template = $path(is_string($node['template'] ?? null) ? $node['template'] : null);
            if ($template !== null) {
                $files[$template]['scan'] = true;
            }
            $node['template'] = $template;
            if (isset($node['files']) && is_array($node['files'])) {
                $node['files'] = array_values(array_filter(array_map($path, $node['files'])));
            }
            $node['reads'] = array_values($node['reads']);
            $nodes[$id] = $node;
        }

        $out = [];
        foreach ($files as $relative => $file) {
            $entry = ['abs' => $file['abs']];
            if ($file['scan']) {
                $texts = TemplateText::scan($file['abs']);
                $entry['texts'] = $texts;
                $entry['lines'] = TemplateText::lines($file['abs'], array_values(array_unique(array_column($texts, 'line'))));
            }
            $out[$relative] = $entry;
        }

        return ['version' => self::VERSION, 'nodes' => $nodes, 'files' => $out];
    }

    /** The manifest as the script element the page script reads. */
    public static function script(string $themeDir): string
    {
        $json = json_encode(
            self::manifest($themeDir),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return '<script type="application/json" id="' . self::MANIFEST_ID . '">' . $json . '</script>';
    }

    /** `site-header` → "Site header", `FAQ` → "FAQ", `HeroBanner` → "Hero banner". */
    public static function humanize(string $name): string
    {
        $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', basename($name, '.php')) ?? $name;
        $words = trim(str_replace(['-', '_'], ' ', $words));
        if ($words === '') {
            return $name;
        }
        // Lower-case every word after the first unless it's an acronym.
        $parts = explode(' ', $words);
        foreach ($parts as $i => $part) {
            if ($i > 0 && !preg_match('/^[A-Z0-9]{2,}$/', $part)) {
                $parts[$i] = strtolower($part);
            }
        }

        return ucfirst(implode(' ', $parts));
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$on = false;
        self::$nodes = [];
        self::$stack = [0];
        self::$next = 0;
        self::$quiet = 0;
    }

    /** @internal Tests read the raw nodes. @return array<int, array<string, mixed>> */
    public static function nodes(): array
    {
        return self::$nodes;
    }

    private static function current(): int
    {
        return (int) end(self::$stack);
    }

    /** A path relative to the theme folder; outside it, from `wp-content/` when possible, else as is. */
    private static function relative(string $real, string $themeDir): string
    {
        $theme = rtrim(realpath($themeDir) ?: $themeDir, '/') . '/';
        if (str_starts_with($real, $theme)) {
            return substr($real, strlen($theme));
        }
        $at = strpos($real, '/wp-content/');

        return $at !== false ? substr($real, $at + 1) : $real;
    }
}
