<?php

declare(strict_types=1);

/**
 * Dynamic smoke test: boots a real, CI-provisioned WordPress install with
 * this theme active, then actually renders every registered MetaBlock and
 * displays every registered Form against a real (if content-empty) post,
 * failing loudly if any of them throw.
 *
 * This complements bin/ci/check-getdata-signature.php rather than
 * replacing it — that check is a static text scan that only catches one
 * specific class of bug (an incompatible getData() signature). This script
 * actually executes the code path a real page view would take, which is
 * the only way to catch the much wider class of runtime bugs a static
 * check can't see: an undefined function call, a template referencing a
 * variable getData() never returns, a WP API used incorrectly, and so on.
 * Both exist because the historical incident this tooling is built around
 * (Blocks/Hero and Blocks/PricingTable silently taking the entire site
 * down — see check-getdata-signature.php's own docblock) is exactly the
 * kind of bug that's cheap to catch dynamically and easy to miss statically.
 *
 * MetaBlock::render(null) falls back to get_the_ID(), which is false
 * outside a real post/loop context — silently no-op'ing instead of
 * actually exercising getData(). So this script requires a real post ID
 * (created by the CI workflow) and renders every block against it
 * explicitly, rather than relying on that fallback.
 *
 * Usage: php bin/ci/smoke-test.php /path/to/wp-load.php <post_id>
 * Exits non-zero (with details on STDERR) if any block or form throws.
 */

$wpLoad = $argv[1] ?? null;
$postId = isset($argv[2]) ? (int) $argv[2] : null;

if ($wpLoad === null || $postId === null) {
    fwrite(STDERR, "Usage: php bin/ci/smoke-test.php /path/to/wp-load.php <post_id>\n");
    exit(1);
}

if (!file_exists($wpLoad)) {
    fwrite(STDERR, "wp-load.php not found at: {$wpLoad}\n");
    exit(1);
}

if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}

require $wpLoad;

use TAW\Core\Block\BlockRegistry;
use TAW\Core\Form\Form;

$failures = [];

foreach (BlockRegistry::getAll() as $id => $block) {
    ob_start();
    try {
        $block->render($postId);
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            'Block "%s" (%s) threw %s: %s in %s:%d',
            $id,
            get_class($block),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    } finally {
        ob_end_clean();
    }
}

foreach (Form::getAll() as $id => $form) {
    ob_start();
    try {
        Form::display($id);
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            'Form "%s" threw %s: %s in %s:%d',
            $id,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    } finally {
        ob_end_clean();
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "Smoke test failed:\n\n" . implode("\n\n", $failures) . "\n");
    exit(1);
}

printf(
    "Smoke test passed — %d block(s) and %d form(s) rendered against post #%d without error.\n",
    count(BlockRegistry::getAll()),
    count(Form::getAll()),
    $postId
);

exit(0);
