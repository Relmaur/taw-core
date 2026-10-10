<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * A site's update policy: what an update changes on its own, read from the
 * theme's `taw.json` ("update" key). Site-owned, never synced. `bin/taw
 * update` follows all of it; the migrations follow `scaffold` and `docs`
 * wherever they run (`update`, `composer update`'s hook, `upgrade`). The
 * weekly framework-sync workflow doesn't read it yet: moving that workflow
 * onto `bin/taw update` is the follow-up (umbrella plan
 * docs/plans/taw-platform.md § 5).
 *
 * Every setting has a default, so a site without taw.json updates the way
 * the defaults say. An invalid file is never half-applied: the policy reports
 * its errors and `update` refuses to run until they're fixed.
 *
 * Pure: no WordPress, no I/O beyond reading the one file (bin/taw loads it
 * before WordPress, like the Schema classes — no ABSPATH guard).
 */
final class Policy
{
    public const FILE = 'taw.json';
    public const SCHEMA = 'vendor/taw/core/resources/schema/taw-json-1.0.json';

    /** What each setting may be, and what it means — the `policy` command shows these. */
    public const SETTINGS = [
        'core' => [
            'default' => 'minor',
            'values' => ['patch', 'minor', 'pinned:<version>'],
            'means' => [
                'patch' => 'taw/core takes bug-fix releases only (1.90.x)',
                'minor' => 'taw/core takes every 1.x release (new features, never a break)',
                'pinned' => 'taw/core stays at exactly this version',
            ],
        ],
        'scaffold' => [
            'default' => 'auto',
            'values' => ['auto', 'off'],
            'means' => [
                'auto' => 'framework-owned files (Tier 1: functions.php, bin/, workflows, framework skills) are replaced, and unedited vite.config.js/phpstan.neon load taw/core\'s base',
                'off' => 'framework-owned files and configs are left alone (reported, not written)',
            ],
        ],
        'manifests' => [
            'default' => 'add+bump',
            'values' => ['add+bump', 'off'],
            'means' => [
                'add+bump' => 'composer.json/package.json get what a new taw/core needs (added keys, raised minimums); nothing is ever removed',
                'off' => 'composer.json/package.json are left alone (suggestions reported)',
            ],
        ],
        'docs' => [
            'default' => 'framework-sections',
            'values' => ['framework-sections', 'off'],
            'means' => [
                'framework-sections' => "the framework's part of the agent docs comes from taw/core: unedited AGENTS.md/CLAUDE.md/Copilot/Windsurf copies become short files that import or point to it; edited ones are left for a person",
                'off' => 'updates leave the agent docs alone',
            ],
        ],
        'checks' => [
            'default' => ['lint', 'phpstan', 'test', 'build'],
            'values' => ['lint', 'phpstan', 'test', 'build', 'smoke'],
            'means' => [
                'lint' => 'PHP syntax of every theme file',
                'phpstan' => 'static analysis (composer run phpstan), when the theme has it',
                'test' => 'the theme\'s unit tests (composer run test), when it has them',
                'build' => 'the front-end build (npm run build), when the theme has one',
                'smoke' => 'load the site and a few pages through WordPress (needs the site running)',
            ],
        ],
        'deliver' => [
            'default' => 'pr',
            'values' => ['pr', 'pr+merge', 'branch'],
            'means' => [
                'pr' => 'the update is committed on a branch and a pull request is opened for you to merge',
                'pr+merge' => 'the pull request is merged when every check passes (merging deploys production)',
                'branch' => 'the update is committed on a branch; nothing is pushed',
            ],
        ],
    ];

    /**
     * @param array{core: string, scaffold: string, manifests: string, docs: string, checks: list<string>, deliver: string} $values
     * @param list<string> $fromFile the settings taw.json set (the rest are defaults)
     * @param list<string> $errors what's wrong with taw.json; empty = valid
     */
    private function __construct(
        private array $values,
        private array $fromFile,
        private array $errors,
        private bool $hasFile,
    ) {
    }

    /** The policy for a theme folder: its taw.json, or the defaults. */
    public static function load(string $themeDir): self
    {
        $file = rtrim($themeDir, '/') . '/' . self::FILE;
        if (!is_file($file)) {
            return self::fromArray([], false);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            $policy = self::fromArray([], true);
            $policy->errors[] = self::FILE . ' isn\'t valid JSON: ' . json_last_error_msg();

            return $policy;
        }
        $update = $data['update'] ?? [];
        if (!is_array($update)) {
            $policy = self::fromArray([], true);
            $policy->errors[] = '"update" must be an object of settings';

            return $policy;
        }

        return self::fromArray($update, true);
    }

    /**
     * @param array<mixed> $update the "update" object
     */
    public static function fromArray(array $update, bool $hasFile = true): self
    {
        $values = self::defaults();
        $fromFile = [];
        $errors = [];
        foreach ($update as $key => $value) {
            if (!is_string($key) || !isset(self::SETTINGS[$key])) {
                $errors[] = sprintf('unknown setting "%s" (known: %s)', (string) $key, implode(', ', array_keys(self::SETTINGS)));
                continue;
            }
            $error = self::check($key, $value);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $values[$key] = $value;
            $fromFile[] = $key;
        }

        /** @var array{core: string, scaffold: string, manifests: string, docs: string, checks: list<string>, deliver: string} $values */
        return new self($values, $fromFile, $errors, $hasFile);
    }

    /**
     * @return array{core: string, scaffold: string, manifests: string, docs: string, checks: list<string>, deliver: string}
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::SETTINGS as $key => $setting) {
            $out[$key] = $setting['default'];
        }

        return $out;
    }

    private static function check(string $key, mixed $value): ?string
    {
        $allowed = self::SETTINGS[$key]['values'];
        if ($key === 'checks') {
            if (!is_array($value) || !array_is_list($value)) {
                return '"checks" must be a list, e.g. ["lint", "test"]';
            }
            foreach ($value as $check) {
                if (!is_string($check) || !in_array($check, $allowed, true)) {
                    return sprintf('unknown check "%s" in "checks" (known: %s)', is_scalar($check) ? (string) $check : gettype($check), implode(', ', $allowed));
                }
            }

            return null;
        }
        if (!is_string($value)) {
            return sprintf('"%s" must be one of: %s', $key, implode(', ', $allowed));
        }
        if ($key === 'core' && str_starts_with($value, 'pinned:')) {
            return preg_match('/^pinned:v?\d+\.\d+\.\d+$/', $value) === 1
                ? null
                : '"core": a pin is "pinned:1.90.0" (an exact version)';
        }

        return in_array($value, $allowed, true) ? null : sprintf('"%s" is "%s"; it must be one of: %s', $key, $value, implode(', ', $allowed));
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasFile(): bool
    {
        return $this->hasFile;
    }

    /** Whether a setting came from taw.json (else it's the default). */
    public function isSet(string $key): bool
    {
        return in_array($key, $this->fromFile, true);
    }

    /**
     * @return array{core: string, scaffold: string, manifests: string, docs: string, checks: list<string>, deliver: string}
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function core(): string
    {
        return $this->values['core'];
    }

    /** The exact version taw/core is pinned to, or null. */
    public function pinnedVersion(): ?string
    {
        return str_starts_with($this->values['core'], 'pinned:') ? ltrim(substr($this->values['core'], 7), 'v') : null;
    }

    /**
     * The Composer constraint the update asks for, given the installed
     * taw/core version: patch → ~1.90.0, minor → ^1.90 (never below what's
     * installed), pinned → the exact version.
     */
    public function coreConstraint(string $installed): string
    {
        if (($pin = $this->pinnedVersion()) !== null) {
            return $pin;
        }
        if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', $installed, $m) !== 1) {
            return '^1.0';
        }
        return $this->values['core'] === 'patch'
            ? sprintf('~%d.%d.%d', $m[1], $m[2], $m[3])
            : sprintf('^%d.%d', $m[1], $m[2]);
    }

    public function scaffold(): bool
    {
        return $this->values['scaffold'] === 'auto';
    }

    public function manifests(): bool
    {
        return $this->values['manifests'] === 'add+bump';
    }

    public function docs(): bool
    {
        return $this->values['docs'] === 'framework-sections';
    }

    /**
     * Whether an on/off setting (scaffold, manifests, docs) lets an update
     * do that kind of change on its own. Migrations name theirs (PolicyGated).
     */
    public function allows(string $setting): bool
    {
        return match ($setting) {
            'scaffold' => $this->scaffold(),
            'manifests' => $this->manifests(),
            'docs' => $this->docs(),
            default => true,
        };
    }

    /** @return list<string> */
    public function checks(): array
    {
        return $this->values['checks'];
    }

    public function deliver(): string
    {
        return $this->values['deliver'];
    }

    /** What a setting's current value means, in words. */
    public function explain(string $key): string
    {
        $means = self::SETTINGS[$key]['means'];
        if ($key === 'checks') {
            $parts = array_map(fn (string $c) => $c . ' (' . $means[$c] . ')', $this->values['checks']);

            return $parts === [] ? 'no checks run' : implode('; ', $parts);
        }
        $value = $this->values[$key];
        if ($key === 'core' && ($pin = $this->pinnedVersion()) !== null) {
            return 'taw/core stays at exactly ' . $pin;
        }

        return $means[$value] ?? $value;
    }

    /**
     * A starter taw.json: every setting at its default, with the schema link.
     * Written the way the scaffolds' Prettier formats JSON (4-space indent,
     * short lists on one line), so a theme whose CI runs `prettier --check`
     * accepts it.
     */
    public static function starter(): string
    {
        $lines = ['{', '    "$schema": ' . json_encode('./' . self::SCHEMA, JSON_UNESCAPED_SLASHES) . ',', '    "update": {'];
        $settings = self::defaults();
        $last = array_key_last($settings);
        foreach ($settings as $key => $value) {
            $lines[] = '        ' . json_encode($key) . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . ($key === $last ? '' : ',');
        }
        $lines[] = '    }';
        $lines[] = '}';

        return str_replace('","', '", "', implode("\n", $lines)) . "\n";
    }
}
