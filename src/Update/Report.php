<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * An update's report in Markdown: the pull request's description, and when
 * something failed, the step-by-step guide a person follows and the prompt
 * "Fix with Claude" receives — the same document (umbrella ADR-0004). Layered:
 * a plain summary for the site owner first, the developer's steps next, the
 * maintainer's detail last.
 */
final class Report
{
    /**
     * @param array<string, mixed> $r the Updater's result
     */
    public static function markdown(array $r): string
    {
        $site = (string) ($r['site'] ?? 'this site');
        $from = ltrim((string) ($r['core']['from'] ?? ''), 'v');
        $to = ltrim((string) ($r['core']['to'] ?? ''), 'v');
        $status = (string) ($r['status'] ?? '');
        $out = [];

        $out[] = '# TAW update: ' . $site . ($from !== '' && $to !== '' && $from !== $to ? " (taw/core {$from} → {$to})" : '');
        $out[] = '';
        $out[] = '**In short:** ' . self::summary($r);
        $out[] = '';

        if ($status === 'refused') {
            $out[] = '## What to do';
            $out[] = '';
            $out[] = (string) ($r['failure']['reason'] ?? '');
            $out[] = '';

            return implode("\n", $out);
        }

        if (($r['changed'] ?? []) !== []) {
            $out[] = '## What changed';
            $out[] = '';
            foreach ($r['changed'] as $path) {
                $out[] = '- `' . $path . '`';
            }
            $out[] = '';
        }
        if (($r['migrations'] ?? []) !== [] || ($r['manual'] ?? []) !== [] || ($r['held'] ?? []) !== []) {
            $out[] = '## Migrations';
            $out[] = '';
            foreach ($r['migrations'] ?? [] as $id) {
                $out[] = '- `' . $id . '` (what, why, by hand, undo: `vendor/bin/taw upgrade --explain ' . $id . '`)';
            }
            foreach ($r['manual'] ?? [] as $step) {
                $out[] = '- **For you:** ' . $step;
            }
            foreach ($r['held'] ?? [] as $note) {
                $out[] = '- **Off in taw.json:** ' . $note;
            }
            $out[] = '';
        }
        if (($r['checks'] ?? []) !== []) {
            $out[] = '## Checks';
            $out[] = '';
            $out[] = '| Check | Result |';
            $out[] = '|---|---|';
            foreach ($r['checks'] as $c) {
                $result = match ($c['status']) {
                    'pass' => '✓ passed',
                    'fail' => '✗ failed',
                    default => 'not run here: ' . ($c['reason'] ?? ''),
                };
                $out[] = '| ' . $c['name'] . ' | ' . $result . ' |';
            }
            $out[] = '';
        }

        if ($status === 'failed') {
            $out = array_merge($out, self::failure($r));
        } elseif (isset($r['delivered'])) {
            $out[] = '## Next';
            $out[] = '';
            $out[] = (string) $r['delivered']['note'];
            $out[] = '';
        }

        $out[] = '<details><summary>For a maintainer: the policy and every command</summary>';
        $out[] = '';
        $out[] = 'Policy (`taw.json`, `vendor/bin/taw policy` explains it): `' . json_encode($r['policy'] ?? [], JSON_UNESCAPED_SLASHES) . '`';
        $out[] = '';
        foreach (array_merge($r['steps'] ?? [], array_filter($r['checks'] ?? [], fn ($c) => ($c['command'] ?? '') !== '')) as $s) {
            $ok = isset($s['ok']) ? ($s['ok'] ? '✓' : '✗') : ($s['status'] === 'pass' ? '✓' : ($s['status'] === 'fail' ? '✗' : '–'));
            $out[] = '- ' . $ok . ' `' . $s['command'] . '`';
        }
        $out[] = '';
        $out[] = '</details>';
        $out[] = '';

        return implode("\n", $out);
    }

    /** @param array<string, mixed> $r */
    private static function summary(array $r): string
    {
        $failure = $r['failure'] ?? null;

        return match ((string) ($r['status'] ?? '')) {
            'updated' => 'the site\'s TAW framework is updated and every check that runs here passed. '
                . (isset($r['delivered']) ? (string) $r['delivered']['note'] : ''),
            'up-to-date' => 'nothing to update: the site already has everything its policy allows.',
            'refused' => 'nothing was changed, because the update couldn\'t start safely (below).',
            'failed' => 'the update stopped at "' . (is_array($failure) ? $failure['step'] : '') . '". Nothing was pushed or deployed; '
                . (isset($r['branch']) ? 'the work so far is on the branch `' . $r['branch'] . '`. ' : '')
                . 'A developer can finish it with the steps below, or hand this whole report to Claude ("Fix with Claude").',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $r
     * @return list<string>
     */
    private static function failure(array $r): array
    {
        $f = $r['failure'];
        $step = (string) $f['step'];
        $out = ['## What failed: ' . $step, ''];
        if (($f['command'] ?? '') !== '') {
            $out[] = 'Command: `' . $f['command'] . '`';
            $out[] = '';
        }
        if (($f['out'] ?? '') !== '') {
            $out[] = '```';
            $out[] = (string) $f['out'];
            $out[] = '```';
            $out[] = '';
        }
        $runbook = dirname(__DIR__, 2) . '/resources/runbooks/' . preg_replace('/[^a-z]/', '', $step) . '.md';
        if (is_file($runbook)) {
            $out[] = trim((string) file_get_contents($runbook));
            $out[] = '';
        }
        $branch = (string) ($r['branch'] ?? '');
        $base = (string) ($r['base'] ?? 'main');
        $out[] = '## Verify';
        $out[] = '';
        $out[] = ($f['command'] ?? '') !== '' ? 'Run `' . $f['command'] . '` again: it should pass.' : 'Run the step again: it should pass.';
        $out[] = '';
        $out[] = '## Finish';
        $out[] = '';
        if ($branch !== '') {
            $out[] = "On the branch `{$branch}` (`git checkout {$branch}`): commit the fix, then push it and open a pull request";
            $out[] = "into `{$base}`: `git push -u origin {$branch}`, then `gh pr create --base {$base} --fill` (or on GitHub).";
            $out[] = "taw-fleet's M merges it; merging deploys production.";
        }
        $out[] = '';
        $out[] = '## Undo';
        $out[] = '';
        $out[] = $branch !== ''
            ? "Nothing reached production. To drop the update: `git checkout {$base} && git branch -D {$branch}`, then `composer install` to restore vendor/."
            : 'Nothing was changed.';
        $out[] = '';

        return $out;
    }
}
