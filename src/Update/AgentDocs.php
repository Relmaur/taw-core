<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * A theme's agent docs (AGENTS.md, CLAUDE.md, Copilot and Windsurf rules).
 *
 * The framework's part ships with taw/core (`resources/agents/<kind>/`) and
 * updates with it. The theme keeps short site-owned files that import
 * (CLAUDE.md, `@vendor/...`) or point to (the others) that part, plus a
 * "This site" section for its own notes. They carry the `taw:agent-doc`
 * marker, and an update never changes a marked file.
 *
 * Before v1.91.0 themes held full copies of the framework docs (Tier 2: a
 * person had to review every diff). plan() finds those copies: one identical
 * to a version the scaffolds once shipped (resources/agents/known.json, from
 * taw-theme's and taw-gutenberg's git history) is unedited, and apply()
 * replaces it with the site template; its text stays in git history. Any
 * other copy holds the site's own notes (on the live fleet, fsspx's and
 * ml-portfolio's CLAUDE.md, 2026-10-10), so it is left as is ("custom") with
 * the steps for a person (BY_HAND).
 *
 * Pure filesystem work, no WordPress (pre-boot: no ABSPATH guard).
 */
final class AgentDocs
{
    public const MARKER = 'taw:agent-doc';

    /**
     * Theme path => site template, per theme kind. `create` = written when
     * missing too (the rest are only converted when the theme has them).
     *
     * @var array<string, array<string, array{template: string, create: bool}>>
     */
    public const FILES = [
        'classic' => [
            'AGENTS.md' => ['template' => 'classic/site/AGENTS.md', 'create' => true],
            'CLAUDE.md' => ['template' => 'classic/site/CLAUDE.md', 'create' => true],
            '.github/copilot-instructions.md' => ['template' => 'classic/site/copilot-instructions.md', 'create' => false],
            '.windsurfrules' => ['template' => 'classic/site/windsurfrules.md', 'create' => false],
        ],
        'block' => [
            'AGENTS.md' => ['template' => 'block/site/AGENTS.md', 'create' => true],
            'CLAUDE.md' => ['template' => 'block/site/CLAUDE.md', 'create' => true],
        ],
    ];

    /** What a person does with a copy the site edited. */
    public const BY_HAND = 'Move this site\'s own notes out of it: copy the file aside, run vendor/bin/taw docs:sync --apply --force, then put your notes back under its "This site" section and commit.';

    public function __construct(private string $themeDir, private string $kind)
    {
    }

    /** Where taw/core keeps the agent docs (this package's resources/agents). */
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/resources/agents';
    }

    /**
     * What an update would do to each doc.
     *
     * @return array{convert: list<string>, custom: list<string>, create: list<string>, current: list<string>}
     */
    public function plan(): array
    {
        $known = self::known()[$this->kind] ?? [];
        $plan = ['convert' => [], 'custom' => [], 'create' => [], 'current' => []];
        foreach (self::FILES[$this->kind] ?? [] as $path => $doc) {
            $file = $this->themeDir . '/' . $path;
            if (!is_file($file)) {
                if ($doc['create']) {
                    $plan['create'][] = $path;
                }
                continue;
            }
            $contents = (string) file_get_contents($file);
            $plan[match (true) {
                str_contains($contents, self::MARKER) => 'current',
                in_array(hash('sha256', $contents), $known[$path] ?? [], true) => 'convert',
                default => 'custom',
            }][] = $path;
        }

        return $plan;
    }

    /**
     * Writes the site templates for the plan's convert and create entries
     * (and, with $force, over the site's edited copies too).
     *
     * @param array{convert: list<string>, custom: list<string>, create: list<string>, current: list<string>} $plan
     * @return list<string> the paths written
     */
    public function apply(array $plan, bool $force = false): array
    {
        $written = [];
        foreach (array_merge($plan['convert'], $plan['create'], $force ? $plan['custom'] : []) as $path) {
            $template = self::root() . '/' . self::FILES[$this->kind][$path]['template'];
            $file = $this->themeDir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            copy($template, $file);
            $written[] = $path;
        }

        return $written;
    }

    /** @var array<string, array<string, list<string>>>|null */
    private static ?array $knownForTests = null;

    /**
     * Tests only: the known versions as text (kind => path => list of
     * contents), or null for resources/agents/known.json.
     *
     * @param array<string, array<string, list<string>>>|null $copies
     */
    public static function useKnown(?array $copies): void
    {
        self::$knownForTests = $copies === null ? null : array_map(
            fn (array $paths) => array_map(fn (array $texts) => array_map(fn (string $t) => hash('sha256', $t), $texts), $paths),
            $copies,
        );
    }

    /** @return array<string, array<string, list<string>>> kind => path => sha256 of every scaffold version */
    private static function known(): array
    {
        if (self::$knownForTests !== null) {
            return self::$knownForTests;
        }
        $data = json_decode((string) file_get_contents(self::root() . '/known.json'), true);

        return is_array($data) ? array_intersect_key($data, self::FILES) : [];
    }
}
