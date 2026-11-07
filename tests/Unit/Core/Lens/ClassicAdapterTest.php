<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use Brain\Monkey\Functions;
use TAW\Core\Lens\ClassicAdapter;
use TAW\Core\Lens\SourceMap;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

final class ClassicAdapterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        SourceMap::resetForTests();
        ClassicAdapter::resetForTests();
        Metabox::resetRegistryForTests();
        OptionsPage::resetForTests();

        foreach (['is_feed', 'is_embed', 'is_robots', 'is_trackback'] as $fn) {
            Functions\when($fn)->justReturn(false);
        }
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => $t === 'page');
        Functions\when('get_post_type')->alias(static fn (int $id): string => $id === 99 ? 'nav_menu_item' : 'page');
        Functions\when('get_post')->alias(static fn (int $id) => new \WP_Post(['ID' => $id, 'post_type' => 'page', 'post_name' => 'p' . $id]));
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->meta[$id . $key] ?? '');
        Functions\when('get_metadata_raw')->alias(fn (string $type, int $id, string $key, bool $single) => $this->meta[$id . $key] ?? ($single ? '' : []));
        Functions\when('get_edit_post_link')->alias(static fn (int $id): string => 'https://site.test/wp-admin/post.php?post=' . $id . '&action=edit');
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => array_key_exists($key, $this->options) ? $this->options[$key] : $default);
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));
        Functions\when('get_attached_file')->justReturn('/uploads/2026/11/hero-photo-scaled.jpg');

        new Metabox(['id' => 'taw_hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [
            ['id' => 'hero_title', 'label' => 'Title', 'type' => 'text'],
            ['id' => 'hero_image', 'label' => 'Image', 'type' => 'image'],
            ['id' => 'hero_items', 'label' => 'Items', 'type' => 'repeater', 'fields' => [['id' => 'q', 'label' => 'Question', 'type' => 'text']]],
        ], 'defaults' => ['hero_title' => 'Welcome']]);
        new OptionsPage(['id' => 'taw-settings', 'title' => 'TAW Settings', 'fields' => [
            ['id' => 'company_phone', 'label' => 'Phone', 'type' => 'text'],
        ], 'defaults' => ['company_phone' => '555-0100']]);

        SourceMap::enable();
    }

    protected function tearDown(): void
    {
        SourceMap::resetForTests();
        ClassicAdapter::resetForTests();
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        OptionsPage::resetForTests();
        parent::tearDown();
    }

    public function testAFieldReadIsRecordedWithWhatTheReaderShowed(): void
    {
        self::assertNull(ClassicAdapter::postMeta(null, 5, '_taw_hero_title', true), 'the read itself is untouched');
        $this->meta['5_taw_hero_items'] = '[{"q":"Why?"}]';
        ClassicAdapter::postMeta(null, 5, '_taw_hero_items', true);
        $this->meta['5_taw_hero_image'] = '37';
        ClassicAdapter::postMeta(null, 5, '_taw_hero_image', true);

        $reads = $this->reads();
        self::assertSame([
            'store' => 'post', 'object' => 5, 'key' => 'hero_title', 'value' => 'Welcome',
            'admin' => 'https://site.test/wp-admin/post.php?post=5&action=edit', 'default' => true, 'type' => 'text', 'label' => 'Title',
        ], $reads['hero_title']);
        self::assertSame(['q' => ['label' => 'Question', 'type' => 'text']], $reads['hero_items']['subs']);
        self::assertSame('hero-photo', $reads['hero_image']['stem'], 'any size or format of the image matches');
    }

    public function testAStoredValueIsNotADefault(): void
    {
        $this->meta['5_taw_hero_title'] = 'Mine';
        ClassicAdapter::postMeta(null, 5, '_taw_hero_title', true);

        self::assertSame('Mine', $this->reads()['hero_title']['value']);
        self::assertArrayNotHasKey('default', $this->reads()['hero_title']);
    }

    public function testOtherMetaIsNotRecorded(): void
    {
        ClassicAdapter::postMeta(null, 5, '_wp_page_template', true);
        ClassicAdapter::postMeta(null, 5, '_taw_not_registered', true);
        ClassicAdapter::postMeta(null, 0, '_taw_hero_title', true);

        self::assertSame([], $this->reads());
    }

    public function testOptionsAndTheSiteTitleAreRecorded(): void
    {
        $this->options['blogname'] = 'Awesome Company';
        self::assertFalse(ClassicAdapter::option(false, 'blogname', false));
        ClassicAdapter::option(false, '_taw_company_phone', false);
        ClassicAdapter::option(false, 'siteurl', false);

        $reads = $this->reads();
        self::assertSame(['store' => 'wp', 'object' => 'options', 'key' => 'blogname', 'type' => 'wp', 'label' => 'Site Title', 'value' => 'Awesome Company', 'admin' => 'https://site.test/wp-admin/options-general.php'], $reads['blogname']);
        self::assertSame('555-0100', $reads['company_phone']['value']);
        self::assertTrue($reads['company_phone']['default']);
        self::assertSame('https://site.test/wp-admin/admin.php?page=taw-settings', $reads['company_phone']['admin']);
        self::assertSame('Phone', $reads['company_phone']['label']);
        self::assertArrayNotHasKey('siteurl', $reads);
    }

    public function testPostTitlesButNotMenuItemTitles(): void
    {
        self::assertSame('About', ClassicAdapter::title('About', 12));
        ClassicAdapter::title('Menu label', 99);

        self::assertSame(['post_title'], array_keys($this->reads()));
        self::assertSame(12, $this->reads()['post_title']['object']);
    }

    public function testMenuItemsKnowThePostTheyLinkTo(): void
    {
        $items = [
            (object) ['ID' => 14, 'title' => 'About', 'type' => 'post_type', 'object_id' => '12'],
            (object) ['ID' => 15, 'title' => 'Docs', 'type' => 'custom'],
        ];
        self::assertSame($items, ClassicAdapter::menu($items, (object) ['term_id' => 3]));

        $reads = $this->reads();
        self::assertSame(12, $reads['item-14']['target']);
        self::assertArrayNotHasKey('target', $reads['item-15']);
        self::assertSame('https://site.test/wp-admin/nav-menus.php?action=edit&menu=3', $reads['item-15']['admin']);
    }

    public function testTemplatePartsOpenNodes(): void
    {
        $theme = sys_get_temp_dir() . '/taw-lens-theme-' . uniqid();
        mkdir($theme . '/template-parts', 0777, true);
        file_put_contents($theme . '/template-parts/site-header.php', '');
        Functions\when('get_stylesheet_directory')->justReturn($theme);
        Functions\when('get_template_directory')->justReturn($theme);

        ob_start();
        ClassicAdapter::openPart($theme . '/template-parts/site-header.php');
        ClassicAdapter::closePart();
        $html = ob_get_clean();

        self::assertSame('<!--taw-lens:1--><!--/taw-lens:1-->', $html);
        $node = SourceMap::nodes()[1];
        self::assertSame(['part', 'template-parts/site-header', 'Site header'], [$node['kind'], $node['name'], $node['label']]);
        exec('rm -rf ' . escapeshellarg($theme));
    }

    public function testFeedsGetNoMap(): void
    {
        SourceMap::resetForTests();
        Functions\when('is_feed')->justReturn(true);

        ClassicAdapter::start();

        self::assertFalse(SourceMap::on());
    }

    /** @return array<string, array<string, mixed>> */
    private function reads(): array
    {
        $out = [];
        foreach (SourceMap::nodes()[0]['reads'] as $read) {
            $out[$read['key']] = $read;
        }

        return $out;
    }
}
