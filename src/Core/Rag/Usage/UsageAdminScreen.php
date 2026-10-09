<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Usage;

use TAW\Core\Form\Turnstile;
use TAW\Core\Rag\Guard\ChatLimits;
use TAW\Core\Rag\Guard\ChatSession;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rest\RagChatEndpoint;
use TAW\Core\Security\ClientIp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TAW Chatbot → Usage: what the chatbot has spent, what it is allowed to
 * spend, and whether its protections are actually on — read-only, so the
 * page itself can never change spend or settings.
 *
 * It also shows the request's own IP as the server resolves it, next to
 * the raw `REMOTE_ADDR` and `X-Forwarded-For`: the one-glance check that
 * {@see ClientIp} is configured right for this host. If the resolved
 * address is the host's proxy instead of the admin's own, every visitor is
 * sharing one rate-limit bucket and `TAW_TRUSTED_PROXIES` needs setting.
 *
 * The admin notices (shown on every TAW Chatbot screen) cover the two
 * states an owner must not miss: chat refused for lack of Turnstile keys,
 * and spend nearing the monthly budget.
 */
final class UsageAdminScreen
{
    private const CAP = 'manage_options';
    private const SLUG = 'taw-rag-usage';
    private const HISTORY_DAYS = 30;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    public function addPage(): void
    {
        add_submenu_page('taw_rag', 'Usage', 'Usage', self::CAP, self::SLUG, [$this, 'renderPage']);
    }

    public function renderNotices(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if (($page !== 'taw_rag' && !str_starts_with($page, 'taw-rag-')) || !current_user_can(self::CAP)) {
            return;
        }

        if (RagSettings::chatPaused()) {
            $this->notice('info', __('The chat is paused (kill switch). Visitors get a "paused" message until it is switched back on.', 'taw-core'));
        }

        if (RagSettings::humanCheck() === 'turnstile' && !Turnstile::isConfigured()) {
            $this->notice('error', __('The chat is refusing every message: the human check is set to Turnstile, but TAW_TURNSTILE_SITE_KEY and TAW_TURNSTILE_SECRET_KEY are not defined in wp-config.php.', 'taw-core'));
        }

        try {
            $status = UsageMeter::status();
        } catch (\Throwable) {
            $this->notice('error', __('The chat is refusing every message: its usage ledger cannot be read. See php bin/taw log:tail.', 'taw-core'));
            return;
        }

        if ($status['month_limit'] > 0 && $status['month_spent'] >= $status['month_limit'] * 0.8) {
            $this->notice('warning', sprintf(
                /* translators: 1: amount spent, 2: monthly budget */
                __('The chat has spent %1$s of its %2$s monthly budget. It pauses on its own before going over.', 'taw-core'),
                UsageMeter::formatUsd($status['month_spent']),
                UsageMeter::formatUsd($status['month_limit'])
            ));
        }
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'taw-core'));
        }

        try {
            $status = UsageMeter::status();
            $history = UsageMeter::history(self::HISTORY_DAYS);
        } catch (\Throwable $e) {
            $status = null;
            $history = [];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Chat Usage', 'taw-core'); ?></h1>
            <p class="description">
                <?php esc_html_e('Spend is metered from the token counts your LLM provider reports, priced with the Budget settings. Compare it with the provider\'s own dashboard now and then — it is the bill of record.', 'taw-core'); ?>
            </p>

            <?php if ($status === null) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('The usage ledger could not be read, so the chat is refusing messages. See php bin/taw log:tail.', 'taw-core'); ?></p></div>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 48rem; margin-top: 1rem;">
                    <tbody>
                        <?php
                        $this->row(__('Spent today', 'taw-core'), UsageMeter::formatUsd($status['day_spent']) . ' / ' . UsageMeter::formatUsd($status['day_limit']));
                        $this->row(__('Spent this month', 'taw-core'), UsageMeter::formatUsd($status['month_spent']) . ' / ' . UsageMeter::formatUsd($status['month_limit']));
                        $this->row(__('Worst case per message', 'taw-core'), UsageMeter::formatUsd(RagChatEndpoint::currentReserveMicros()));
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Protections', 'taw-core'); ?></h2>
            <table class="widefat striped" style="max-width: 48rem;">
                <tbody>
                    <?php
                    $this->row(__('Chat', 'taw-core'), RagSettings::chatPaused() ? __('Paused', 'taw-core') : __('On', 'taw-core'));
                    $this->row(__('Human check', 'taw-core'), $this->humanCheckLabel());
                    $this->row(__('Per visitor', 'taw-core'), sprintf(
                        /* translators: 1: messages per 10 minutes, 2: messages per day */
                        __('%1$d messages per 10 minutes, %2$d per day', 'taw-core'),
                        RagSettings::rateBurst(),
                        RagSettings::rateDaily()
                    ));
                    $this->row(__('Whole site', 'taw-core'), sprintf(
                        /* translators: %d: messages per minute */
                        __('%d messages per minute', 'taw-core'),
                        RagSettings::rateGlobalPerMinute()
                    ));
                    $this->row(__('Per message', 'taw-core'), sprintf(
                        /* translators: 1: max characters, 2: max answer tokens, 3: max tool rounds */
                        __('up to %1$d characters in, %2$d tokens per answer, %3$d tool rounds', 'taw-core'),
                        RagSettings::maxMessageChars(),
                        RagSettings::maxOutputTokens(),
                        RagSettings::maxToolIterations()
                    ));
                    $this->row(__('Per conversation', 'taw-core'), sprintf(
                        /* translators: 1: messages, 2: minutes */
                        __('%1$d messages or %2$d minutes, then a new human check', 'taw-core'),
                        ChatSession::MAX_MESSAGES,
                        intdiv(ChatSession::TTL_SECONDS, 60)
                    ));
                    $this->row(__('History sent back', 'taw-core'), sprintf(
                        /* translators: 1: turns, 2: characters per turn */
                        __('last %1$d turns, %2$d characters each', 'taw-core'),
                        ChatLimits::HISTORY_TURNS,
                        ChatLimits::HISTORY_TURN_CHARS
                    ));
                    ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Your address, as this server sees it', 'taw-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Rate limits count messages per address. If "Resolved" below is not your own public IP but your host\'s proxy, every visitor shares one limit: add the proxy to TAW_TRUSTED_PROXIES in wp-config.php.', 'taw-core'); ?>
            </p>
            <table class="widefat striped" style="max-width: 48rem;">
                <tbody>
                    <?php
                    $this->row(__('Resolved', 'taw-core'), ClientIp::get());
                    $this->row('REMOTE_ADDR', (string) ($_SERVER['REMOTE_ADDR'] ?? '—'));
                    $this->row('X-Forwarded-For', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '—'));
                    ?>
                </tbody>
            </table>

            <h2><?php
                /* translators: %d: number of days */
                echo esc_html(sprintf(__('Last %d days', 'taw-core'), self::HISTORY_DAYS));
            ?></h2>
            <?php if ($history === []) : ?>
                <p><?php esc_html_e('No metered calls yet.', 'taw-core'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 48rem;">
                    <thead><tr>
                        <th><?php esc_html_e('Day', 'taw-core'); ?></th>
                        <th><?php esc_html_e('Calls', 'taw-core'); ?></th>
                        <th><?php esc_html_e('Input tokens', 'taw-core'); ?></th>
                        <th><?php esc_html_e('Output tokens', 'taw-core'); ?></th>
                        <th><?php esc_html_e('Cost', 'taw-core'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($history as $day) : ?>
                            <tr>
                                <td><?php echo esc_html($day['day']); ?></td>
                                <td><?php echo esc_html(number_format_i18n($day['requests'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($day['prompt_tokens'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($day['completion_tokens'])); ?></td>
                                <td><?php echo esc_html(UsageMeter::formatUsd($day['cost_micros'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function humanCheckLabel(): string
    {
        if (RagSettings::humanCheck() === 'off') {
            return __('Off', 'taw-core');
        }

        return Turnstile::isConfigured()
            ? __('Turnstile, once per conversation', 'taw-core')
            : __('Turnstile, but its keys are missing — chat is refusing messages', 'taw-core');
    }

    private function row(string $label, string $value): void
    {
        printf('<tr><th scope="row" style="width: 14rem;">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
    }

    private function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p><strong>%s</strong> %s</p></div>',
            esc_attr($type),
            esc_html__('TAW Chatbot:', 'taw-core'),
            esc_html($message)
        );
    }
}
