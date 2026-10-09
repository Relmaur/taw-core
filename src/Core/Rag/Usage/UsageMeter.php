<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Usage;

use TAW\Core\Log\Logger;
use TAW\Core\Rag\RagSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meters every paid LLM call and enforces the chat budget.
 *
 * {@see \TAW\Core\Rag\Llm\LlmClient} reports the `usage` block of each
 * response here; spend is priced with the per-1M-token prices from
 * {@see RagSettings} and kept in integer micro-dollars (1 USD = 1 000 000),
 * so sums never drift. Since a price is "USD per 1M tokens", one token
 * costs exactly `price` micro-dollars.
 *
 * Enforcement is a pre-check ({@see self::blockReason()}): a chat request is
 * refused when today's or this month's spend *plus the worst case the
 * request itself could cost* would cross the limit — so the ceiling is
 * genuinely hard, never "one request over". If spend can't be read at all
 * (the ledger table is missing, the database errors), the chat fails
 * closed rather than running unmetered.
 *
 * Embedding calls (indexing) are metered but never blocked: they're tiny,
 * and admin- or publish-triggered.
 */
final class UsageMeter
{
    public const KIND_CHAT = 'chat';
    public const KIND_EMBEDDING = 'embedding';

    public const BLOCK_DAILY = 'daily';
    public const BLOCK_MONTHLY = 'monthly';
    public const BLOCK_UNAVAILABLE = 'unavailable';

    private const SCHEMA_OPTION = 'taw_rag_usage_schema';
    private const STATUS_TRANSIENT = 'taw_rag_usage_status';
    private const ALERTS_OPTION = 'taw_rag_budget_alerts';
    private const STATUS_TTL = 60;
    private const MONTHLY_WARNING_RATIO = 0.8;

    private static bool $schemaReady = false;

    public static function costMicros(string $kind, int $promptTokens, int $completionTokens): int
    {
        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);

        if ($kind === self::KIND_EMBEDDING) {
            return (int) ceil($promptTokens * RagSettings::embeddingPrice());
        }

        return (int) ceil(
            $promptTokens * RagSettings::chatInputPrice()
            + $completionTokens * RagSettings::chatOutputPrice()
        );
    }

    /**
     * Add one call's usage to today's ledger row. Never throws — a metering
     * failure is logged, and the next {@see self::blockReason()} (which
     * reads the same table) fails closed if the ledger is really broken.
     */
    public static function record(string $kind, string $model, int $promptTokens, int $completionTokens): void
    {
        global $wpdb;

        $cost = self::costMicros($kind, $promptTokens, $completionTokens);

        try {
            self::ensureSchema();

            $sql = $wpdb->prepare(
                'INSERT INTO ' . UsageSchema::table() . ' (day, kind, model, requests, prompt_tokens, completion_tokens, cost_micros)'
                . ' VALUES (%s, %s, %s, 1, %d, %d, %d)'
                . ' ON DUPLICATE KEY UPDATE requests = requests + 1, prompt_tokens = prompt_tokens + %d,'
                . ' completion_tokens = completion_tokens + %d, cost_micros = cost_micros + %d',
                self::today(),
                $kind,
                substr($model, 0, 100),
                max(0, $promptTokens),
                max(0, $completionTokens),
                $cost,
                max(0, $promptTokens),
                max(0, $completionTokens),
                $cost
            );

            if ($wpdb->query($sql) === false) {
                throw new \RuntimeException((string) $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            Logger::error('rag.usage_record_failed', 'Could not record LLM usage.', [
                'kind' => $kind,
                'model' => $model,
                'cost_micros' => $cost,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        delete_transient(self::STATUS_TRANSIENT);
        self::maybeWarn();
    }

    /**
     * Spend so far and the limits, all in micro-dollars.
     *
     * @return array{day_spent: int, month_spent: int, day_limit: int, month_limit: int}
     * @throws \RuntimeException When the ledger can't be read.
     */
    public static function status(): array
    {
        $today = self::today();
        $cached = get_transient(self::STATUS_TRANSIENT);

        if (is_array($cached) && ($cached['day'] ?? null) === $today) {
            $daySpent = (int) $cached['day_spent'];
            $monthSpent = (int) $cached['month_spent'];
        } else {
            self::ensureSchema();
            $daySpent = self::spentSince($today);
            $monthSpent = self::spentSince(self::monthStart());
            set_transient(self::STATUS_TRANSIENT, [
                'day' => $today,
                'day_spent' => $daySpent,
                'month_spent' => $monthSpent,
            ], self::STATUS_TTL);
        }

        return [
            'day_spent' => $daySpent,
            'month_spent' => $monthSpent,
            'day_limit' => self::usdToMicros(RagSettings::dailyBudgetUsd()),
            'month_limit' => self::usdToMicros(RagSettings::monthlyBudgetUsd()),
        ];
    }

    /**
     * Why a request that could cost up to $reserveMicros must be refused,
     * or null when it fits both budgets. Sends the "chat paused" alert the
     * first time a period's budget blocks a request.
     */
    public static function blockReason(int $reserveMicros): ?string
    {
        try {
            $status = self::status();
        } catch (\Throwable $e) {
            Logger::error('rag.usage_unavailable', 'Could not read LLM spend; refusing chat to stay within budget.', [
                'error' => $e->getMessage(),
            ]);

            return self::BLOCK_UNAVAILABLE;
        }

        $reserveMicros = max(0, $reserveMicros);

        if ($status['month_limit'] <= 0 || $status['month_spent'] + $reserveMicros > $status['month_limit']) {
            self::alertOnce(
                self::monthKey() . ':month:paused',
                'Chat paused: monthly budget reached',
                sprintf(
                    "The chatbot on %s has paused for the rest of the month.\n\nSpent this month: %s of a %s budget.\n\nRaise the monthly budget under TAW Chatbot → Budget to resume it sooner.",
                    self::siteName(),
                    self::formatUsd($status['month_spent']),
                    self::formatUsd($status['month_limit'])
                )
            );

            return self::BLOCK_MONTHLY;
        }

        if ($status['day_limit'] <= 0 || $status['day_spent'] + $reserveMicros > $status['day_limit']) {
            self::alertOnce(
                self::today() . ':day:paused',
                'Chat paused: daily budget reached',
                sprintf(
                    "The chatbot on %s has paused until tomorrow (site time).\n\nSpent today: %s of a %s daily budget.",
                    self::siteName(),
                    self::formatUsd($status['day_spent']),
                    self::formatUsd($status['day_limit'])
                )
            );

            return self::BLOCK_DAILY;
        }

        return null;
    }

    /**
     * Per-day totals for the last $days days (site time), newest first.
     *
     * @return list<array{day: string, requests: int, prompt_tokens: int, completion_tokens: int, cost_micros: int}>
     */
    public static function history(int $days): array
    {
        global $wpdb;

        self::ensureSchema();

        $since = gmdate('Y-m-d', (int) strtotime(self::today() . ' -' . max(0, $days - 1) . ' days'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT day, SUM(requests) AS requests, SUM(prompt_tokens) AS prompt_tokens,'
                . ' SUM(completion_tokens) AS completion_tokens, SUM(cost_micros) AS cost_micros'
                . ' FROM ' . UsageSchema::table() . ' WHERE day >= %s GROUP BY day ORDER BY day DESC',
                $since
            ),
            ARRAY_A
        );

        return array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'requests' => (int) $row['requests'],
            'prompt_tokens' => (int) $row['prompt_tokens'],
            'completion_tokens' => (int) $row['completion_tokens'],
            'cost_micros' => (int) $row['cost_micros'],
        ], is_array($rows) ? $rows : []);
    }

    public static function usdToMicros(float $usd): int
    {
        return (int) round(max(0.0, $usd) * 1_000_000);
    }

    public static function formatUsd(int $micros): string
    {
        return '$' . number_format($micros / 1_000_000, 4);
    }

    /**
     * The ledger's rollback path: drop the table and every option and
     * transient it owns. Spend history is gone afterwards, so only
     * `bin/taw rag:usage --uninstall` (behind a confirmation) calls it. If
     * the chatbot is still enabled, the next metered call simply recreates
     * an empty ledger.
     */
    public static function uninstall(): void
    {
        global $wpdb;

        if ($wpdb->query('DROP TABLE IF EXISTS ' . UsageSchema::table()) === false) {
            throw new \RuntimeException('Could not drop the usage ledger: ' . (string) $wpdb->last_error);
        }

        delete_option(self::SCHEMA_OPTION);
        delete_option(self::ALERTS_OPTION);
        delete_transient(self::STATUS_TRANSIENT);
        self::$schemaReady = false;
    }

    /**
     * Reset per-request state — tests only.
     */
    public static function resetForTests(): void
    {
        self::$schemaReady = false;
    }

    private static function spentSince(string $day): int
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(cost_micros), 0) FROM ' . UsageSchema::table() . ' WHERE day >= %s',
            $day
        ));

        if ($value === null) {
            throw new \RuntimeException('Usage ledger query failed: ' . (string) $wpdb->last_error);
        }

        return (int) $value;
    }

    private static function ensureSchema(): void
    {
        global $wpdb;

        if (self::$schemaReady) {
            return;
        }

        if (get_option(self::SCHEMA_OPTION) !== UsageSchema::VERSION) {
            if ($wpdb->query(UsageSchema::createStatement()) === false) {
                throw new \RuntimeException('Could not create the usage ledger: ' . (string) $wpdb->last_error);
            }
            update_option(self::SCHEMA_OPTION, UsageSchema::VERSION, false);
        }

        self::$schemaReady = true;
    }

    private static function maybeWarn(): void
    {
        try {
            $status = self::status();
        } catch (\Throwable) {
            return;
        }

        if ($status['month_limit'] <= 0) {
            return;
        }

        if ($status['month_spent'] >= $status['month_limit'] * self::MONTHLY_WARNING_RATIO) {
            self::alertOnce(
                self::monthKey() . ':month:80',
                'Chat budget 80% used',
                sprintf(
                    "The chatbot on %s has used %s of its %s monthly budget.\n\nIt pauses on its own before going over. Raise the budget under TAW Chatbot → Budget if this is expected traffic.",
                    self::siteName(),
                    self::formatUsd($status['month_spent']),
                    self::formatUsd($status['month_limit'])
                )
            );
        }
    }

    /**
     * Send an alert email once per key. Keys start with the period they
     * belong to, so flags from earlier months are dropped as they go.
     */
    private static function alertOnce(string $key, string $subject, string $body): void
    {
        $sent = get_option(self::ALERTS_OPTION, []);
        $sent = is_array($sent) ? $sent : [];

        if (!empty($sent[$key])) {
            return;
        }

        $month = self::monthKey();
        $sent = array_filter(
            $sent,
            static fn (mixed $value, int|string $existing): bool => str_starts_with((string) $existing, $month),
            ARRAY_FILTER_USE_BOTH
        );
        $sent[$key] = true;
        update_option(self::ALERTS_OPTION, $sent, false);

        Logger::warning('rag.budget', $subject, ['alert' => $key]);

        $recipients = apply_filters('taw_rag_budget_alert_recipients', [(string) get_option('admin_email')]);
        $recipients = array_values(array_filter(
            is_array($recipients) ? array_map('strval', $recipients) : [],
            static fn (string $email): bool => $email !== ''
        ));

        if ($recipients !== []) {
            wp_mail($recipients, '[' . self::siteName() . '] ' . $subject, $body);
        }
    }

    private static function today(): string
    {
        return (string) current_time('Y-m-d');
    }

    private static function monthStart(): string
    {
        return self::monthKey() . '-01';
    }

    private static function monthKey(): string
    {
        return (string) current_time('Y-m');
    }

    private static function siteName(): string
    {
        $name = (string) get_option('blogname');

        return $name !== '' ? $name : 'this site';
    }
}
