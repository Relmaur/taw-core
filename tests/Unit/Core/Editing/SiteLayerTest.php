<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use TAW\Core\Editing\Bypass;
use TAW\Core\Editing\Resolver;
use TAW\Core\Editing\SiteLayer;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class SiteLayerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        parent::tearDown();
    }

    private function layer(EditingPolicy $definition, bool $bypass = false): SiteLayer
    {
        Functions\when('current_user_can')->justReturn($bypass);

        return new SiteLayer(Resolver::resolve($definition), new Bypass('taw_unlock_editing', []));
    }

    private static function request(string $method, string $route): \WP_REST_Request
    {
        return new \WP_REST_Request($method, $route);
    }

    public function test_routes_map_to_areas(): void
    {
        $this->assertSame('templates', SiteLayer::areaOf('/wp/v2/templates'));
        $this->assertSame('templates', SiteLayer::areaOf('/wp/v2/templates/twentytwentyfive//home'));
        $this->assertSame('templates', SiteLayer::areaOf('/wp/v2/templates/twentytwentyfive//home/autosaves'));
        $this->assertSame('templateParts', SiteLayer::areaOf('/wp/v2/template-parts/x//header'));
        $this->assertSame('globalStyles', SiteLayer::areaOf('/wp/v2/global-styles/12'));
        $this->assertSame('navigation', SiteLayer::areaOf('/wp/v2/navigation/5'));
        $this->assertNull(SiteLayer::areaOf('/wp/v2/templates-extra'), 'A longer route must not match by prefix alone.');
        $this->assertNull(SiteLayer::areaOf('/wp/v2/pages/3'));
    }

    public function test_writes_to_locked_areas_are_refused(): void
    {
        $layer = $this->layer(Schema::editing()->preset('structured'));

        $result = $layer->guardRest(null, null, self::request('POST', '/wp/v2/templates/theme//home'));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('taw_editing_site_locked', $result->get_error_code());
        $this->assertSame(['status' => 403, 'area' => 'templates'], $result->error_data['taw_editing_site_locked']);

        $this->assertInstanceOf(\WP_Error::class, $layer->guardRest(null, null, self::request('DELETE', '/wp/v2/template-parts/theme//footer')));
        $this->assertInstanceOf(\WP_Error::class, $layer->guardRest(null, null, self::request('PUT', '/wp/v2/global-styles/9')));
    }

    public function test_reads_are_never_blocked(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'));

        $this->assertNull($layer->guardRest(null, null, self::request('GET', '/wp/v2/templates')));
        $this->assertNull($layer->guardRest(null, null, self::request('GET', '/wp/v2/global-styles/9')));
    }

    public function test_open_areas_and_other_routes_pass(): void
    {
        $layer = $this->layer(Schema::editing()->preset('structured'));

        $this->assertNull($layer->guardRest(null, null, self::request('POST', '/wp/v2/navigation/5')), 'navigation stays open at structured');
        $this->assertNull($layer->guardRest(null, null, self::request('POST', '/wp/v2/pages')));
    }

    public function test_bypass_users_can_write_everywhere(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'), true);

        $this->assertNull($layer->guardRest(null, null, self::request('POST', '/wp/v2/templates')));
    }

    public function test_an_earlier_result_is_kept(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'));
        $earlier = new \WP_Error('other', 'x');

        $this->assertSame($earlier, $layer->guardRest($earlier, null, self::request('POST', '/wp/v2/templates')));
    }

    public function test_template_mode(): void
    {
        $this->assertSame(['supportsTemplateMode' => false], $this->layer(Schema::editing()->preset('structured'))->editorSettings([]));
        $this->assertSame([], $this->layer(Schema::editing()->preset('guided'))->editorSettings([]));
        $this->assertSame([], $this->layer(Schema::editing()->preset('locked'), true)->editorSettings([]));
    }

    public function test_site_editor_is_hidden_and_refused_at_locked(): void
    {
        Functions\expect('remove_submenu_page')->twice();
        Functions\expect('wp_die')->once();

        $layer = $this->layer(Schema::editing()->preset('locked'));
        $layer->hideSiteEditor();
        $layer->refuseSiteEditor();
    }

    public function test_site_editor_stays_below_locked_and_for_bypass(): void
    {
        Functions\expect('remove_submenu_page')->never();
        Functions\expect('wp_die')->never();

        foreach ([$this->layer(Schema::editing()->preset('structured')), $this->layer(Schema::editing()->preset('locked'), true)] as $layer) {
            $layer->hideSiteEditor();
            $layer->refuseSiteEditor();
        }
    }

    public function test_register(): void
    {
        $layer = $this->layer(Schema::editing());
        $layer->register();

        $this->assertSame(10, has_filter('rest_pre_dispatch', [$layer, 'guardRest']));
        $this->assertSame(20, has_filter('block_editor_settings_all', [$layer, 'editorSettings']));
        $this->assertSame(999, has_action('admin_menu', [$layer, 'hideSiteEditor']));
        $this->assertSame(10, has_action('load-site-editor.php', [$layer, 'refuseSiteEditor']));
    }
}
