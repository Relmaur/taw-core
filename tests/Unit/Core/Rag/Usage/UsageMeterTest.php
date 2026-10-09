<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Usage;

use Brain\Monkey\Functions;
use TAW\Core\Log\LogSinkInterface;
use TAW\Core\Log\Logger;
use TAW\Core\Rag\Usage\UsageMeter;
use TAW\Core\Rag\Usage\UsageSchema;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

/**
 * Runs the meter's real SQL against FakeWpdb (in-memory SQLite, with
 * MySQL's upsert translated) rather than asserting on query strings, so
 * the sums and period boundaries are actually exercised.
 */
final class UsageMeterTest extends TestCase
{
    private FakeWpdb $wpdb;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<array{to: mixed, subject: string}> */
    private array $mail = [];

    private string $today = '2026-10-09';

    /** @var list<array<string, mixed>> */
    private array $logEntries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        UsageMeter::resetForTests();

        $this->options = [
            'admin_email' => 'owner@example.com',
            'blogname' => 'Test Parish',
            '_taw_rag_budget_daily_usd' => '1',
            '_taw_rag_budget_monthly_usd' => '10',
            '_taw_rag_price_chat_input' => '0.15',
            '_taw_rag_price_chat_output' => '0.60',
            '_taw_rag_price_embedding' => '0.02',
        ];

        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('current_time')->alias(fn (string $format): string => date($format, (int) strtotime($this->today)));
        Functions\when('wp_mail')->alias(function (mixed $to, string $subject): bool {
            $this->mail[] = ['to' => $to, 'subject' => $subject];
            return true;
        });

        $entries = &$this->logEntries;
        Logger::setSinks(new class ($entries) implements LogSinkInterface {
            /** @param list<array<string, mixed>> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function write(array $entry): void
            {
                $this->entries[] = $entry;
            }
        });
    }

    protected function tearDown(): void
    {
        Logger::reset();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_cost_is_priced_per_million_tokens_in_micro_dollars(): void
    {
        // 1000 × 0.15 + 500 × 0.60 = 450 micro-dollars.
        $this->assertSame(450, UsageMeter::costMicros(UsageMeter::KIND_CHAT, 1000, 500));
        // Embeddings bill input only.
        $this->assertSame(20, UsageMeter::costMicros(UsageMeter::KIND_EMBEDDING, 1000, 999));
        // Fractions round up, so the meter never under-counts.
        $this->assertSame(1, UsageMeter::costMicros(UsageMeter::KIND_CHAT, 1, 0));
        $this->assertSame(0, UsageMeter::costMicros(UsageMeter::KIND_CHAT, -5, -5));
    }

    public function test_recording_creates_the_ledger_and_accumulates_per_day_kind_and_model(): void
    {
        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 1000, 500);
        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 2000, 100);
        UsageMeter::record(UsageMeter::KIND_EMBEDDING, 'text-embedding-3-small', 5000, 0);

        $rows = $this->wpdb->get_results('SELECT * FROM ' . UsageSchema::table() . ' ORDER BY kind');

        $this->assertCount(2, $rows);
        $this->assertSame('chat', $rows[0]['kind']);
        $this->assertSame(2, (int) $rows[0]['requests']);
        $this->assertSame(3000, (int) $rows[0]['prompt_tokens']);
        $this->assertSame(600, (int) $rows[0]['completion_tokens']);
        $this->assertSame(450 + 360, (int) $rows[0]['cost_micros']);
        $this->assertSame(100, (int) $rows[1]['cost_micros']);
        $this->assertSame(UsageSchema::VERSION, $this->options['taw_rag_usage_schema']);
    }

    public function test_status_separates_today_from_the_rest_of_the_month(): void
    {
        $this->seed('2026-09-30', 9_000_000); // last month: ignored
        $this->seed('2026-10-01', 2_000_000); // this month, not today
        $this->seed('2026-10-09', 300_000);   // today

        $status = UsageMeter::status();

        $this->assertSame(300_000, $status['day_spent']);
        $this->assertSame(2_300_000, $status['month_spent']);
        $this->assertSame(1_000_000, $status['day_limit']);
        $this->assertSame(10_000_000, $status['month_limit']);
    }

    public function test_a_request_that_fits_both_budgets_is_allowed(): void
    {
        $this->seed('2026-10-09', 500_000);

        $this->assertNull(UsageMeter::blockReason(10_000));
        $this->assertSame([], $this->mail);
    }

    public function test_the_worst_case_reserve_blocks_before_the_daily_limit_is_crossed(): void
    {
        $this->seed('2026-10-09', 995_000);

        // 995 000 + 10 000 > 1 000 000 — refused even though spend is under the limit.
        $this->assertSame(UsageMeter::BLOCK_DAILY, UsageMeter::blockReason(10_000));
        $this->assertNull(UsageMeter::blockReason(5_000));
    }

    public function test_the_monthly_limit_wins_and_alerts_once_per_period(): void
    {
        $this->seed('2026-10-02', 9_999_000);

        $this->assertSame(UsageMeter::BLOCK_MONTHLY, UsageMeter::blockReason(10_000));
        $this->assertSame(UsageMeter::BLOCK_MONTHLY, UsageMeter::blockReason(10_000));

        $this->assertCount(1, $this->mail);
        $this->assertSame(['owner@example.com'], $this->mail[0]['to']);
        $this->assertStringContainsString('monthly budget reached', $this->mail[0]['subject']);
    }

    public function test_a_zero_budget_pauses_the_chat(): void
    {
        $this->options['_taw_rag_budget_daily_usd'] = '0';

        $this->assertSame(UsageMeter::BLOCK_DAILY, UsageMeter::blockReason(0));
    }

    public function test_an_unreadable_ledger_fails_closed(): void
    {
        // The schema option claims the table exists, but it doesn't.
        $this->options['taw_rag_usage_schema'] = UsageSchema::VERSION;

        $this->assertSame(UsageMeter::BLOCK_UNAVAILABLE, UsageMeter::blockReason(0));
        $this->assertContains('rag.usage_unavailable', array_column($this->logEntries, 'code'));
    }

    public function test_a_failed_write_is_logged_and_never_thrown(): void
    {
        UsageMeter::status(); // create the table
        $this->wpdb->failOnQueryContaining = 'INSERT INTO';

        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 10, 10);

        $this->assertContains('rag.usage_record_failed', array_column($this->logEntries, 'code'));
    }

    public function test_crossing_eighty_percent_of_the_month_sends_one_warning(): void
    {
        $this->seed('2026-10-03', 7_999_900);

        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 1000, 0); // +150 → 80.0005 %
        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 1000, 0);

        $this->assertCount(1, $this->mail);
        $this->assertStringContainsString('80%', $this->mail[0]['subject']);
    }

    public function test_alert_flags_from_earlier_months_are_pruned(): void
    {
        $this->options['taw_rag_budget_alerts'] = ['2026-09:month:80' => true];
        $this->seed('2026-10-03', 9_000_000);

        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 1, 0);

        $this->assertSame(['2026-10:month:80' => true], $this->options['taw_rag_budget_alerts']);
    }

    public function test_the_recipient_filter_can_silence_alerts(): void
    {
        \Brain\Monkey\Filters\expectApplied('taw_rag_budget_alert_recipients')->andReturn([]);
        $this->seed('2026-10-09', 1_000_000);

        UsageMeter::blockReason(0);

        $this->assertSame([], $this->mail);
    }

    public function test_history_groups_by_day_newest_first(): void
    {
        $this->seed('2026-10-08', 100, 'chat', 'a');
        $this->seed('2026-10-08', 50, 'embedding', 'b');
        $this->seed('2026-10-09', 7, 'chat', 'a');
        $this->seed('2026-09-01', 999, 'chat', 'a'); // outside the 7-day window

        $history = UsageMeter::history(7);

        $this->assertSame(['2026-10-09', '2026-10-08'], array_column($history, 'day'));
        $this->assertSame(150, $history[1]['cost_micros']);
        $this->assertSame(2, $history[1]['requests']);
    }

    public function test_uninstall_drops_the_ledger_and_its_options(): void
    {
        UsageMeter::record(UsageMeter::KIND_CHAT, 'gpt-4o-mini', 1, 1);
        $this->options['taw_rag_budget_alerts'] = ['2026-10:month:80' => true];

        UsageMeter::uninstall();

        $tables = $this->wpdb->get_results("SELECT name FROM sqlite_master WHERE type='table' AND name='" . UsageSchema::table() . "'");
        $this->assertSame([], $tables);
        $this->assertArrayNotHasKey('taw_rag_usage_schema', $this->options);
        $this->assertArrayNotHasKey('taw_rag_budget_alerts', $this->options);
    }

    private function seed(string $day, int $costMicros, string $kind = 'chat', string $model = 'gpt-4o-mini'): void
    {
        UsageMeter::status(); // ensure the table exists
        $this->wpdb->query($this->wpdb->prepare(
            'INSERT INTO ' . UsageSchema::table() . ' (day, kind, model, requests, prompt_tokens, completion_tokens, cost_micros)'
            . ' VALUES (%s, %s, %s, 1, 0, 0, %d)',
            $day,
            $kind,
            $model,
            $costMicros
        ));
        $this->transients = [];
    }
}
