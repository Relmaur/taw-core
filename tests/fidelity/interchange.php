<?php
/**
 * The fidelity suite's driver: `wp --user=admin eval-file interchange.php <command> …`.
 *
 *   export <file>          a full-state snapshot (what `content:export --migrate` writes)
 *   plan <file>            the import's dry run; fails unless it reports 0 changes
 *   apply <file>           import it (settings included); fails on any failed record
 *   undo                   undo the latest import
 *   diff <base> <target>   `ChangeSet::between()`; fails unless it has 0 operations
 *
 * Runs as a user (`--user=admin`), like `content:import`.
 */

// No strict_types: `wp eval-file` evaluates this file.

use TAW\Core\Content\ChangeSet;
use TAW\Core\Content\Exporter;
use TAW\Core\Content\Importer;

/** @param list<string> $lines */
function fid_fail(string $what, array $lines = []): never
{
    fwrite(STDERR, "FAIL: {$what}\n" . implode('', array_map(static fn (string $l): string => "  {$l}\n", array_slice($lines, 0, 60))));
    exit(1);
}

/** @return array<string, mixed> */
function fid_read(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    is_array($data) || fid_fail("{$file} is not a JSON snapshot");

    return $data;
}

/** One line per record the plan would change. @param array<string, mixed> $plan @return list<string> */
function fid_changed(array $plan): array
{
    $lines = [];
    foreach ($plan['records'] as $record) {
        $op = (string) ($record['op'] ?? '');
        $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
        if ($op === 'skip' || ($changes === [] && in_array($op, ['update', 'unchanged'], true))) {
            continue;
        }
        $key = $record['kind'] . ':' . ($record['path'] ?? $record['slug'] ?? $record['key'] ?? $record['filename'] ?? '?');
        $detail = [];
        foreach ($changes as $field => $change) {
            $detail[] = $field . ' ' . (is_array($change) ? json_encode(['old' => $change['old'] ?? null, 'new' => $change['new'] ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        }
        $lines[] = "{$op} {$key}" . ($detail === [] ? '' : ': ' . substr(implode('; ', $detail), 0, 400));
    }

    return $lines;
}

/** @var list<string> $args the arguments after the file, set by `wp eval-file` */
$command = $args[0] ?? '';

switch ($command) {
    case 'export':
        $snapshot = (new Exporter())->snapshot([
            'include_media' => true, 'all_media' => true, 'include_drafts' => true, 'include_users' => true,
            'include_settings' => true, 'include_options' => true, 'include_terms' => true,
        ]);
        file_put_contents($args[1], json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        printf("exported %d posts, %d media, %d menus\n", count($snapshot['posts'] ?? []), count($snapshot['media'] ?? []), count($snapshot['menus'] ?? []));
        break;

    case 'plan':
        $plan = (new Importer())->plan(fid_read($args[1]));
        $changed = fid_changed($plan);
        $changed === [] || fid_fail(count($changed) . ' record(s) would change on a second import', $changed);
        printf("plan: 0 changes (%d records)\n", count($plan['records']));
        break;

    case 'apply':
        $report = (new Importer())->apply(fid_read($args[1]), ['policy' => 'update', 'include_settings' => true]);
        $report['error'] === null || fid_fail('import error: ' . $report['error']);
        $failed = array_map(static fn ($f): string => is_array($f) ? json_encode($f, JSON_UNESCAPED_SLASHES) : (string) $f, $report['failed'] ?? []);
        $failed === [] || fid_fail(count($failed) . ' record(s) failed', $failed);
        $warnings = array_values(array_filter($report['warnings'] ?? [], static fn ($w): bool => !str_contains((string) $w, "isn't registered")));
        printf("apply: %d created, %d updated, %d deleted, %d skipped, %d media downloaded\n", count($report['created']), count($report['updated']),
            count($report['deleted']), count($report['skipped']), $report['media_sideloaded']);
        foreach ($warnings as $warning) {
            echo "  warning: {$warning}\n";
        }
        break;

    case 'undo':
        $result = (new Importer())->undo();
        echo 'undo: ', json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
        break;

    case 'diff':
        $ops = ChangeSet::between(fid_read($args[1]), fid_read($args[2]))['operations'];
        $lines = array_map(static fn (array $op): string => $op['op'] . ' ' . $op['target']['kind'] . ':' . ($op['target']['path'] ?? $op['target']['key'] ?? $op['target']['slug'] ?? '?')
            . ' ' . substr((string) json_encode($op['changes'] ?? $op['fields'] ?? $op['post'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 300), $ops);
        $lines === [] || fid_fail(count($lines) . ' operation(s) between ' . basename($args[1]) . ' and ' . basename($args[2]), $lines);
        echo 'diff: 0 operations', "\n";
        break;

    default:
        fid_fail("unknown command '{$command}'");
}
