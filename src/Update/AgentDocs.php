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
 * Until v1.92 themes held full copies of the framework docs (Tier 2: a
 * person had to review every diff). plan() finds those copies and apply()
 * replaces each with the site template — every client copy was the
 * scaffold's text, unedited (checked on the live fleet, 2026-10-09). The
 * previous text stays in git history. A copy a site did edit is replaced
 * too, so the report lists every conversion for the update's pull request.
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
     * @return array{convert: list<string>, create: list<string>, current: list<string>}
     */
    public function plan(): array
    {
        $plan = ['convert' => [], 'create' => [], 'current' => []];
        foreach (self::FILES[$this->kind] ?? [] as $path => $doc) {
            $file = $this->themeDir . '/' . $path;
            if (!is_file($file)) {
                if ($doc['create']) {
                    $plan['create'][] = $path;
                }
                continue;
            }
            $plan[str_contains((string) file_get_contents($file), self::MARKER) ? 'current' : 'convert'][] = $path;
        }

        return $plan;
    }

    /**
     * Writes the site templates for the plan's convert and create entries.
     *
     * @param array{convert: list<string>, create: list<string>, current: list<string>} $plan
     * @return list<string> the paths written
     */
    public function apply(array $plan): array
    {
        $written = [];
        foreach (array_merge($plan['convert'], $plan['create']) as $path) {
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
}
