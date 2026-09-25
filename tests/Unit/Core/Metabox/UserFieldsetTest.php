<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * ADR-0008 decision 5: `"user"` targets (Profile, Edit User, Add New User).
 */
final class UserFieldsetTest extends TestCase
{
    /** @var array<string, mixed> "id|key" → value */
    private array $userMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'book'], true));
        Functions\when('get_user_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->userMeta["{$id}|{$key}"] ?? '');
        Functions\when('update_user_meta')->alias(function (int $id, string $key, $value): bool {
            $this->userMeta["{$id}|{$key}"] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function (int $id, string $key): bool {
            unset($this->userMeta["{$id}|{$key}"]);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        $_POST = [];
        unset($GLOBALS['pagenow']);
        parent::tearDown();
    }

    /**
     * @param list<string> $screens
     * @param list<array<string, mixed>> $fields
     */
    private function box(array $screens, array $fields = []): Metabox
    {
        return new Metabox(['id' => 'author_details', 'title' => 'Author', 'screens' => $screens, 'fields' => $fields ?: [
            ['id' => 'author_twitter', 'type' => 'url'],
            ['id' => 'author_public', 'type' => 'checkbox'],
            ['id' => 'author_bio', 'type' => 'text', 'conditions' => [['id' => 'author_public', 'value' => '1']]],
        ]]);
    }

    public function test_user_targets_never_count_as_a_page_slug(): void
    {
        $box = $this->box(['book', 'user']);

        $this->assertTrue($box->targetsUsers());
        $this->assertSame(['book'], Metabox::screensToPostTypes(['book', 'user']), 'not "page", as a slug would');
        $this->assertSame(['book'], Metabox::postTypesWithMetabox());
        $this->assertSame(['_taw_author_twitter', '_taw_author_public', '_taw_author_bio'], array_keys(Metabox::fieldsFor('user')));
        $this->assertSame([], Metabox::fieldsFor('post', 'page'));
    }

    public function test_user_screens_are_hooked_only_for_user_targets(): void
    {
        $postOnly = new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['book'], 'fields' => []]);
        $this->assertFalse(has_action('show_user_profile', [$postOnly, 'render_user_edit']));

        $box = $this->box(['user']);
        foreach (['show_user_profile' => 'render_user_edit', 'edit_user_profile' => 'render_user_edit', 'user_new_form' => 'render_user_new',
                  'personal_options_update' => 'save_user', 'edit_user_profile_update' => 'save_user', 'user_register' => 'save_user'] as $hook => $method) {
            $this->assertSame(10, has_action($hook, [$box, $method]), $hook);
        }
    }

    private function postForm(array $values): void
    {
        $_POST = $values + ['author_details_nonce' => 'n'];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '');
        Functions\when('esc_url_raw')->alias(static fn ($v) => (string) $v);
    }

    public function test_save_user_sanitizes_and_applies_conditions(): void
    {
        $box = $this->box(['user']);
        $this->userMeta['7|_taw_author_bio'] = 'stale';
        $this->postForm(['_taw_author_twitter' => 'https://x.test/ada', '_taw_author_bio' => 'hidden']);
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id): bool => $cap === 'edit_user' && $id === 7);

        $box->save_user(7);

        $this->assertSame(['7|_taw_author_twitter' => 'https://x.test/ada'], $this->userMeta, 'unchecked checkbox and hidden bio deleted');
    }

    public function test_save_user_needs_the_nonce_and_edit_user(): void
    {
        $box = $this->box(['user']);
        $this->postForm(['_taw_author_twitter' => 'https://x.test/ada']);

        Functions\when('current_user_can')->justReturn(false);
        $box->save_user(7);
        $this->assertSame([], $this->userMeta);

        Functions\when('current_user_can')->justReturn(true);
        unset($_POST['author_details_nonce']);
        $box->save_user(7);
        $this->assertSame([], $this->userMeta, 'wp_insert_user() without the form: no nonce, no write');
    }

    public function test_validation_errors_show_on_the_user_screen_they_belong_to(): void
    {
        $box = $this->box(['user'], [['id' => 'author_twitter', 'type' => 'text', 'label' => 'Twitter', 'required' => true]]);
        $this->postForm(['_taw_author_twitter' => '']);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('__')->returnArg(1);
        $transients = [];
        Functions\when('set_transient')->alias(static function (string $key, $value) use (&$transients): bool {
            $transients[$key] = $value;
            return true;
        });
        $box->save_user(7);
        $this->assertArrayHasKey('taw_validation_errors_user_7', $transients);

        Functions\when('get_transient')->alias(static fn (string $key) => $transients[$key] ?? false);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(7);
        $GLOBALS['pagenow'] = 'profile.php';

        ob_start();
        Metabox::displayValidationErrors();
        $this->assertStringContainsString('Twitter is required', (string) ob_get_clean());
    }

    public function test_user_readers(): void
    {
        $this->userMeta['7|_taw_author_public'] = '1';
        $this->userMeta['7|_taw_author_links'] = '[{"href":"https://a.test"}]';

        $this->assertTrue(Metabox::user(7)->bool('author_public'));
        $this->assertSame([['href' => 'https://a.test']], Metabox::user(7)->repeater('author_links'));
        $this->assertSame('1', Metabox::get_user(7, 'author_public'));
    }
}
