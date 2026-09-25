<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\OptionsPage;

use Brain\Monkey\Functions;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Core\Schema\Definition\OptionsPage as OptionsPageDefinition;
use TAW\Core\Schema\JsonLoader;
use TAW\Core\Schema\Schema;
use TAW\Core\Schema\Validator;
use TAW\Tests\TestCase;

/**
 * Options pages over REST (data layer Phase 2, Step 4): opt-in per page,
 * `private` → /wp/v2/settings, `public` → also GET taw/v1/options/<page>.
 */
final class OptionsPageRestTest extends TestCase
{
    /** @var array<string, array<string, mixed>> option name → register_setting() args */
    private array $settings = [];

    /** @var array<string, int> option name → register_setting() calls */
    private array $settingCalls = [];

    /** @var array<string, array<string, mixed>> route → args */
    private array $routes = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('register_setting')->alias(function (string $group, string $name, array $args = []): void {
            $this->settings[$name] = $args + ['group' => $group];
            $this->settingCalls[$name] = ($this->settingCalls[$name] ?? 0) + 1;
        });
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args): bool {
            $this->routes["{$namespace}{$route}"] = $args;
            return true;
        });
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(OptionsPage::class, 'fieldRegistry'))->setValue(null, []);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function page(array $config = []): OptionsPage
    {
        return new OptionsPage($config + [
            'id'     => 'site',
            'title'  => 'Site',
            'fields' => [
                ['id' => 'phone', 'type' => 'text', 'label' => 'Phone', 'required' => true],
                ['id' => 'website', 'type' => 'url', 'label' => 'Website'],
                ['id' => 'show_banner', 'type' => 'checkbox'],
                ['id' => 'max_items', 'type' => 'number'],
                ['id' => 'logo', 'type' => 'image'],
                ['id' => 'offices', 'type' => 'repeater', 'fields' => [
                    ['id' => 'city', 'type' => 'text'],
                    ['id' => 'main', 'type' => 'checkbox'],
                ]],
                ['id' => 'social', 'type' => 'group', 'fields' => [['id' => 'x', 'type' => 'url']]],
            ],
        ]);
    }

    public function test_a_page_without_rest_exposes_nothing(): void
    {
        $page = $this->page();
        $this->assertFalse(has_action('rest_api_init', [$page, 'register_rest']));

        $page->register_settings();

        $this->assertSame(['sanitize_callback', 'group'], array_keys($this->settings['_taw_phone']));
        $this->assertSame([], $this->routes);
    }

    public function test_an_unknown_rest_value_counts_as_off(): void
    {
        $page = $this->page(['rest' => 'yes']);

        $this->assertFalse(has_action('rest_api_init', [$page, 'register_rest']));
    }

    public function test_private_registers_each_option_in_wp_v2_settings_with_its_rest_type(): void
    {
        $page = $this->page(['rest' => 'private']);
        $this->assertSame(10, has_action('rest_api_init', [$page, 'register_rest']));

        $page->register_rest();

        $types = array_map(static fn (array $args): string => $args['show_in_rest']['schema']['type'], $this->settings);
        $this->assertSame([
            '_taw_phone'       => 'string',
            '_taw_website'     => 'string',
            '_taw_show_banner' => 'boolean',
            '_taw_max_items'   => 'number',
            '_taw_logo'        => 'integer',
            '_taw_offices'     => 'string', // the form's own JSON string
            '_taw_social_x'    => 'string',
        ], $types);
        $this->assertSame('Phone', $this->settings['_taw_phone']['show_in_rest']['schema']['title']);
        $this->assertSame('site', $this->settings['_taw_phone']['group']);
        $this->assertSame([], $this->routes, 'private: no public route');
        $this->assertSame(10, has_filter('rest_request_before_callbacks', [$page, 'validate_rest_settings']));
    }

    public function test_settings_register_once_per_request(): void
    {
        $page = $this->page(['rest' => 'private']);

        $page->register_rest();
        $page->register_settings(); // admin_init in the same request

        $this->assertSame([1], array_values(array_unique($this->settingCalls)));
    }

    public function test_public_serves_decoded_values_to_anyone(): void
    {
        $page = $this->page(['rest' => 'public']);
        $page->register_rest();

        $route = $this->routes['taw/v1/options/site'] ?? null;
        $this->assertNotNull($route);
        $this->assertSame('GET', $route['methods']);
        $this->assertSame('__return_true', $route['permission_callback']);

        $this->options = [
            '_taw_phone'       => '555',
            '_taw_show_banner' => '1',
            '_taw_max_items'   => '12',
            '_taw_logo'        => '49',
            '_taw_offices'     => '[{"city":"Monterrey","main":"1"}]',
            '_taw_social_x'    => 'https://x.test/a',
        ];

        $this->assertSame([
            'phone'       => '555',
            'website'     => '',
            'show_banner' => true,
            'max_items'   => 12,
            'logo'        => 49,
            'offices'     => [['city' => 'Monterrey', 'main' => true]],
            'social_x'    => 'https://x.test/a',
        ], $page->rest_public_values());
    }

    public function test_a_page_id_that_is_not_a_slug_gets_no_public_route(): void
    {
        $this->page(['id' => 'site (main)', 'rest' => 'public'])->register_rest();

        $this->assertSame([], $this->routes);
    }

    private function settingsWrite(array $params, string $method = 'POST', string $route = '/wp/v2/settings'): \WP_REST_Request
    {
        return new \WP_REST_Request($method, $route, $params);
    }

    public function test_settings_writes_get_the_forms_validation_as_a_400(): void
    {
        $page = $this->page(['rest' => 'private']);
        Functions\when('current_user_can')->justReturn(true);

        $result = $page->validate_rest_settings(null, [], $this->settingsWrite([
            '_taw_phone'   => '',
            '_taw_website' => 'not a url',
            '_taw_logo'    => null, // null deletes: not validated
            'title'        => 'Core setting',
        ]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rest_invalid_param', $result->get_error_code());
        $this->assertSame(400, $result->error_data['rest_invalid_param']['status']);
        $this->assertSame(['_taw_phone', '_taw_website'], array_keys($result->error_data['rest_invalid_param']['params']));

        $this->assertNull($page->validate_rest_settings(null, [], $this->settingsWrite(['_taw_phone' => '555', '_taw_website' => 'https://a.test'])));
    }

    public function test_validation_leaves_reads_other_routes_and_unauthorized_requests_alone(): void
    {
        $page = $this->page(['rest' => 'private']);
        $bad = ['_taw_phone' => ''];

        Functions\when('current_user_can')->justReturn(true);
        $this->assertNull($page->validate_rest_settings(null, [], $this->settingsWrite($bad, 'GET')));
        $this->assertNull($page->validate_rest_settings(null, [], $this->settingsWrite($bad, 'POST', '/wp/v2/posts')));
        $earlier = new \WP_Error('rest_forbidden', 'No');
        $this->assertSame($earlier, $page->validate_rest_settings($earlier, [], $this->settingsWrite($bad)));

        Functions\when('current_user_can')->justReturn(false);
        $this->assertNull($page->validate_rest_settings(null, [], $this->settingsWrite($bad)), 'the endpoint answers 401/403 itself');
    }

    public function test_the_sanitize_callback_keeps_the_old_value_outside_wp_admin(): void
    {
        $page = $this->page(['rest' => 'private']);
        $page->register_settings();
        $this->options['_taw_phone'] = 'old';

        // Outside wp-admin add_settings_error() doesn't exist (a REST request);
        // an earlier test in the run may have defined it, so stub it then.
        if (function_exists('add_settings_error')) {
            Functions\when('add_settings_error')->justReturn(null);
        }
        $this->assertSame('old', ($this->settings['_taw_phone']['sanitize_callback'])(''));
    }

    public function test_definition_and_json_carry_rest(): void
    {
        $this->assertSame('public', Schema::optionsPage('site')->rest('public')->fields([['id' => 'a', 'type' => 'text']])->toArray()['rest']);
        $this->assertSame(['private', 'public'], OptionsPageDefinition::REST_MODES);
        $this->assertStringContainsString('rest must be', implode("\n", Schema::optionsPage('site')->rest('open')->fields([['id' => 'a', 'type' => 'text']])->problems()));

        $json = ['version' => 1, 'kind' => 'options_page', 'key' => 'site', 'fields' => [['id' => 'a', 'type' => 'text']]];
        $this->assertSame([], Validator::validate($json + ['rest' => 'public']));
        $this->assertSame('private', JsonLoader::toDefinition($json + ['rest' => 'private'])->toArray()['rest']);
        $this->assertStringContainsString('/rest: must be one of private, public', implode("\n", Validator::validate($json + ['rest' => 'yes'])));
    }
}
