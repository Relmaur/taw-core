<?php

declare(strict_types=1);

/**
 * CI check: every MetaBlock's getData() must be declared exactly
 * `getData(int|false $postId): array`.
 *
 * A narrower signature (e.g. `getData(int $postId)`) is a PHP fatal
 * "incompatible declaration" error against TAW\Core\Block\MetaBlock's
 * abstract method — and because BlockLoader::loadAll() auto-instantiates
 * every block on every request, one bad signature anywhere in Blocks/
 * takes the entire site down, not just that block. This has happened
 * for real in this project (Blocks/Hero, Blocks/PricingTable) — this
 * script exists so it can't happen silently again.
 *
 * Pure static text scan — no WordPress/DB bootstrap needed, safe to run
 * in CI on every push/PR.
 *
 * Usage: php bin/ci/check-getdata-signature.php
 * Exit 0 = all clear. Exit 1 = at least one violation found (printed).
 */

// The theme to check: the first argument, else the working directory (CI
// runs from the theme's checkout). Shipped in taw/core since v1.91.0, so it
// no longer finds the theme from its own location.
$themeDir = rtrim($argv[1] ?? (string) getcwd(), '/');
$blocksDir = $themeDir . '/Blocks';

if (!is_dir($blocksDir)) {
    fwrite(STDOUT, "No Blocks/ directory found — nothing to check.\n");
    exit(0);
}

$violations = [];
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($blocksDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);

    if ($contents === false) {
        continue;
    }

    // Only check files that actually extend MetaBlock — Block (presentational)
    // subclasses don't implement getData() at all.
    if (!preg_match('/\bextends\s+MetaBlock\b/', $contents)) {
        continue;
    }

    $checked++;

    if (!preg_match('/function\s+getData\s*\(([^)]*)\)/', $contents, $match)) {
        $violations[] = [
            'file' => $path,
            'reason' => 'extends MetaBlock but declares no getData() method at all (abstract method must be implemented)',
        ];
        continue;
    }

    $params = $match[1];

    // Accept "int|false $postId" or "false|int $postId", with any whitespace.
    $validSignature = preg_match('/^\s*(int\s*\|\s*false|false\s*\|\s*int)\s+\$postId\s*$/', $params);

    if (!$validSignature) {
        $violations[] = [
            'file' => $path,
            'reason' => "getData({$params}) — must be exactly getData(int|false \$postId): array",
        ];
    }
}

if (empty($violations)) {
    fwrite(STDOUT, "✓ getData() signature check passed ({$checked} MetaBlock file(s) checked).\n");
    exit(0);
}

fwrite(STDOUT, "✗ getData() signature check FAILED:\n\n");

foreach ($violations as $violation) {
    $relative = ltrim(str_replace($themeDir, '', $violation['file']), '/');
    fwrite(STDOUT, "  {$relative}\n    {$violation['reason']}\n\n");
}

fwrite(STDOUT, "Every MetaBlock subclass must declare getData(int|false \$postId): array to match\n");
fwrite(STDOUT, "TAW\\Core\\Block\\MetaBlock's abstract signature — a narrower type is a PHP fatal\n");
fwrite(STDOUT, "error that takes down every page on the site, not just this block.\n");

exit(1);
