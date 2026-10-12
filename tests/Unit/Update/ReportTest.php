<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TAW\Update\Report;

/**
 * Issue #198: on fsspx the page cache was cleared while the deploy still ran,
 * and was rebuilt from the old code. The report says when to clear it.
 */
final class ReportTest extends TestCase
{
    public function test_a_delivered_update_says_to_clear_caches_after_the_deploy(): void
    {
        $md = Report::markdown([
            'site' => 'acme',
            'status' => 'updated',
            'delivered' => ['how' => 'pr', 'url' => 'https://github.com/acme/theme/pull/7', 'note' => 'Pull request opened for you to merge. Merging deploys production.'],
        ]);

        $this->assertStringContainsString("## Next\n\nPull request opened for you to merge. Merging deploys production.\n\n" . Report::CACHE_AFTER_DEPLOY, $md);
    }

    public function test_a_failed_update_says_it_in_the_finish_steps(): void
    {
        $md = Report::markdown([
            'site' => 'acme',
            'status' => 'failed',
            'branch' => 'taw/update-20261012-1200',
            'failure' => ['step' => 'checks', 'command' => 'composer run test', 'out' => ''],
        ]);

        $finish = substr($md, (int) strpos($md, '## Finish'));
        $this->assertStringContainsString(Report::CACHE_AFTER_DEPLOY, substr($finish, 0, (int) strpos($finish, '## Undo')));
    }

    public function test_nothing_to_deploy_says_nothing_about_caches(): void
    {
        $this->assertStringNotContainsString(Report::CACHE_AFTER_DEPLOY, Report::markdown(['site' => 'acme', 'status' => 'up-to-date']));
        $this->assertStringNotContainsString(Report::CACHE_AFTER_DEPLOY, Report::markdown(['site' => 'acme', 'status' => 'refused', 'failure' => ['reason' => 'Uncommitted changes.']]));
    }
}
