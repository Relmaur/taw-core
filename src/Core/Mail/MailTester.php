<?php

declare(strict_types=1);

namespace TAW\Core\Mail;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MailTester — WP Admin page for sending test emails.
 *
 * Registers under Tools → Emails. Lets developers pick a compiled template,
 * fill in merge-tag values, and fire a test send.
 *
 * Usage:
 *   (new MailTester())->register();
 */
class MailTester
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminPage']);
        add_action('admin_post_taw_test_mail', [$this, 'handleTestMail']);
    }

    public function addAdminPage(): void
    {
        add_management_page(
            'Test Emails',
            'Test Emails',
            'manage_options',
            'taw-test-emails',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $templates = MailTemplate::getAvailable();
        ?>
        <div class="wrap">
            <h1>Test Email System</h1>

            <?php if (isset($_GET['sent'])): ?>
                <?php if ($_GET['sent'] === 'success'): ?>
                    <div class="notice notice-success is-dismissible"><p>Test email sent successfully!</p></div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible">
                        <p>Error: <?php echo esc_html($_GET['message'] ?? 'Unknown error'); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('taw_test_mail_action', 'taw_test_mail_nonce'); ?>
                <input type="hidden" name="action" value="taw_test_mail">

                <table class="form-table">
                    <tr>
                        <th><label for="recipient_email">Recipient Email</label></th>
                        <td>
                            <input type="email" id="recipient_email" name="recipient_email"
                                value="<?php echo esc_attr(get_option('admin_email')); ?>"
                                class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="template">Template</label></th>
                        <td>
                            <select id="template" name="template" class="regular-text" required>
                                <option value="">-- Select a template --</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?php echo esc_attr($t); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('-', ' ', $t))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($templates)): ?>
                                <p class="description">
                                    No compiled templates found. Add <code>.html</code> files to your theme's
                                    <code>mails/html/</code> directory.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="subject">Subject</label></th>
                        <td>
                            <input type="text" id="subject" name="subject"
                                value="Test Email from <?php echo esc_attr(get_bloginfo('name')); ?>"
                                class="regular-text" required>
                        </td>
                    </tr>
                </table>

                <h2>Merge-tag Variables <span class="description">(replace <code>{{key}}</code> in the template)</span></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="var_heading">heading</label></th>
                        <td><input type="text" id="var_heading" name="variables[heading]"
                            value="This is a Test Email" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="var_content">content</label></th>
                        <td><textarea id="var_content" name="variables[content]" rows="4"
                            class="large-text">This is test content for your email template.</textarea></td>
                    </tr>
                    <tr>
                        <th><label for="var_button_text">button_text</label></th>
                        <td><input type="text" id="var_button_text" name="variables[button_text]"
                            value="Visit Website" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="var_button_url">button_url</label></th>
                        <td><input type="url" id="var_button_url" name="variables[button_url]"
                            value="<?php echo esc_attr(home_url()); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <?php submit_button('Send Test Email'); ?>
            </form>
        </div>
        <?php
    }

    public function handleTestMail(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('taw_test_mail_action', 'taw_test_mail_nonce');

        $recipient = sanitize_email($_POST['recipient_email']);
        $template  = sanitize_text_field($_POST['template']);
        $subject   = sanitize_text_field($_POST['subject']);
        $variables = array_map('sanitize_text_field', $_POST['variables'] ?? []);

        $variables = array_merge([
            'site_name'   => get_bloginfo('name'),
            'site_url'    => home_url(),
        ], $variables);

        try {
            (new Mailer())
                ->to($recipient)
                ->subject($subject)
                ->template($template)
                ->setVariables($variables)
                ->send();

            wp_redirect(add_query_arg(['page' => 'taw-test-emails', 'sent' => 'success'], admin_url('tools.php')));
        } catch (\Exception $e) {
            wp_redirect(add_query_arg([
                'page'    => 'taw-test-emails',
                'sent'    => 'error',
                'message' => urlencode($e->getMessage()),
            ], admin_url('tools.php')));
        }

        exit;
    }
}
