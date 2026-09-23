<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Schema\Definition\Definition;
use TAW\Helpers\Framework;

// No ABSPATH guard: `bin/taw schema:validate` uses readFile()/toDefinition()
// before (and without) WordPress.

/**
 * Loads schema definitions from JSON files (ADR-0004 §§ 4–6).
 *
 * WHERE: a `taw-schema/` folder in the child theme, the parent theme and
 * wp-content, each scanned one level deep (so `taw-schema/post-types/*.json`
 * works too). Which folder a file comes from sets its precedence — PHP
 * definitions still outrank every file (see Source).
 *
 * HOW: one entity per file. Files are read, validated, and turned into the
 * same Definition builders the PHP API uses — so a JSON fieldset and a PHP
 * fieldset are indistinguishable once they reach the Registry and Compiler.
 *
 * COST: in production, the validated result is cached in a transient keyed
 * by every file's path, modification time and size, plus the taw/core
 * version. Editing, adding or removing a file changes the key, so the cache
 * never serves stale definitions. Outside production, files are re-read and
 * re-validated on every request, so mistakes surface immediately.
 */
final class JsonLoader
{
    private const CACHE_PREFIX = 'taw_schema_json_';

    /**
     * Load every discovered file into $registry. A broken file is reported
     * with a _doing_it_wrong() notice and skipped; the rest still load.
     */
    public static function loadInto(Registry $registry): void
    {
        foreach (self::loadDiscovered() as $entry) {
            $registry->add(self::toDefinition($entry['data']), Source::json($entry['path'], $entry['rank']));
        }
    }

    /**
     * The folders to scan, highest precedence first, as dir => rank.
     *
     * The `taw_schema_paths` filter can add or remove folders; a TAW theme
     * adding its own folder would use a Source::RANK_* constant as the rank.
     *
     * @return array<string, int>
     */
    public static function discoveryPaths(): array
    {
        $paths = [];
        $template = get_template_directory() . '/taw-schema';
        $stylesheet = get_stylesheet_directory() . '/taw-schema';

        // With no child theme, stylesheet and template are the same folder;
        // scan it once, as the parent theme.
        if ((realpath(get_stylesheet_directory()) ?: $stylesheet) !== (realpath(get_template_directory()) ?: $template)) {
            $paths[$stylesheet] = Source::RANK_CHILD_THEME;
        }
        $paths[$template] = Source::RANK_PARENT_THEME;

        if (defined('WP_CONTENT_DIR')) {
            $paths[WP_CONTENT_DIR . '/taw-schema'] = Source::RANK_WP_CONTENT;
        }

        // A filter can return anything; fall back to the defaults if it
        // doesn't return an array.
        $filtered = apply_filters('taw_schema_paths', $paths);

        return is_array($filtered) ? $filtered : $paths;
    }

    /**
     * Every *.json file under the given folders (and their direct
     * subfolders), in a stable order. A folder reachable twice (symlinks)
     * is only scanned once.
     *
     * @param array<string, int> $paths dir => rank
     * @return list<array{path: string, rank: int}>
     */
    public static function findFiles(array $paths): array
    {
        $files = [];
        $seenDirs = [];

        foreach ($paths as $dir => $rank) {
            $real = realpath((string) $dir);
            if ($real === false || !is_dir($real) || isset($seenDirs[$real])) {
                continue;
            }
            $seenDirs[$real] = true;

            $matches = [...(glob($real . '/*.json') ?: []), ...(glob($real . '/*/*.json') ?: [])];
            sort($matches);

            foreach ($matches as $file) {
                $files[] = ['path' => $file, 'rank' => (int) $rank];
            }
        }

        return $files;
    }

    /**
     * Read, decode and validate one file.
     *
     * @return array{data: array<string, mixed>|null, errors: list<string>}
     */
    public static function readFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['data' => null, 'errors' => ['/: file could not be read']];
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['data' => null, 'errors' => ['/: invalid JSON — ' . $e->getMessage()]];
        }

        $errors = Validator::validate($data);
        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors];
        }

        // Keys the Validator can't judge alone (WordPress name limits,
        // reserved names) are enforced by the Definition constructors.
        /** @var array<string, mixed> $data */
        try {
            self::toDefinition($data);
        } catch (\InvalidArgumentException $e) {
            return ['data' => null, 'errors' => ['/key: ' . $e->getMessage()]];
        }

        return ['data' => $data, 'errors' => []];
    }

    /**
     * Turn a validated definition array into its PHP builder.
     *
     * @param array<string, mixed> $data Output of a successful readFile().
     */
    public static function toDefinition(array $data): Definition
    {
        $key = (string) $data['key'];

        $definition = match ($data['kind']) {
            'post_type' => self::postType($key, $data),
            'taxonomy'  => Schema::taxonomy($key)->for(...$data['for'])->args($data['args'] ?? []),
            'fieldset'  => self::fieldset($key, $data),
            'options_page' => self::optionsPage($key, $data),
            'editing'   => self::editing($data),
            default     => throw new \InvalidArgumentException('Unknown kind: ' . (string) $data['kind']),
        };

        if (isset($data['labels']) && method_exists($definition, 'labels')) {
            $definition->labels((string) $data['labels']['singular'], (string) $data['labels']['plural']);
        }

        return $definition->override((bool) ($data['override'] ?? false));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function postType(string $key, array $data): Definition
    {
        $postType = Schema::postType($key)->args($data['args'] ?? []);

        if (isset($data['editing'])) {
            $postType->editing($data['editing']);
        }

        return $postType;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function editing(array $data): Definition
    {
        $policy = Schema::editing();

        if (isset($data['preset'])) {
            $policy->preset((string) $data['preset']);
        }
        if (isset($data['bypass']['capability'])) {
            $policy->bypass((string) $data['bypass']['capability']);
        }
        foreach ($data['layers'] ?? [] as $layer => $value) {
            $policy->layer((string) $layer, $value);
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fieldset(string $key, array $data): Definition
    {
        $fieldset = Schema::fieldset($key)
            ->on(...$data['on'])
            ->fields($data['fields'])
            ->with($data['config'] ?? []);

        foreach (['title', 'context', 'priority', 'prefix'] as $name) {
            if (isset($data[$name])) {
                $fieldset->{$name}((string) $data[$name]);
            }
        }

        return $fieldset;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionsPage(string $key, array $data): Definition
    {
        $page = Schema::optionsPage($key)->fields($data['fields'])->with($data['config'] ?? []);

        if (isset($data['title'])) {
            $page->title((string) $data['title']);
        }
        if (isset($data['menu_title'])) {
            $page->menuTitle((string) $data['menu_title']);
        }
        if (isset($data['capability'])) {
            $page->capability((string) $data['capability']);
        }

        return $page;
    }

    /**
     * Discover, read and validate — through the production cache.
     *
     * @return list<array{path: string, rank: int, data: array<string, mixed>}>
     */
    private static function loadDiscovered(): array
    {
        $files = self::findFiles(self::discoveryPaths());
        if ($files === []) {
            return [];
        }

        $useCache = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
        $cacheKey = self::CACHE_PREFIX . self::fingerprint($files);

        if ($useCache) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                /** @var list<array{path: string, rank: int, data: array<string, mixed>}> $cached */
                return $cached;
            }
        }

        $loaded = [];
        foreach ($files as $file) {
            $result = self::readFile($file['path']);

            if ($result['data'] === null) {
                foreach ($result['errors'] as $error) {
                    Registry::warn(__METHOD__, sprintf('Schema file %s is invalid and was skipped — %s', $file['path'], $error));
                }
                continue;
            }

            $loaded[] = ['path' => $file['path'], 'rank' => $file['rank'], 'data' => $result['data']];
        }

        if ($useCache) {
            // Only valid files are cached; a broken file is re-read (and
            // re-reported) until it's fixed, because fixing it changes its
            // mtime and therefore the key.
            set_transient($cacheKey, $loaded, DAY_IN_SECONDS);
        }

        return $loaded;
    }

    /**
     * Cache key input: every file's path, mtime and size, plus the taw/core
     * version (a new version may validate or hydrate differently).
     *
     * @param list<array{path: string, rank: int}> $files
     */
    public static function fingerprint(array $files): string
    {
        $parts = [Framework::version()];
        foreach ($files as $file) {
            $parts[] = $file['path'] . '|' . $file['rank'] . '|' . (int) @filemtime($file['path']) . '|' . (int) @filesize($file['path']);
        }

        return md5(implode("\n", $parts));
    }
}
