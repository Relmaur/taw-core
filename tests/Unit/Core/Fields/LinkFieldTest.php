<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Fields;

use Brain\Monkey\Functions;
use TAW\Core\Content\FieldCodec;
use TAW\Core\Fields\Link;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Rest\FieldMetaRegistrar;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\Validator;
use TAW\Taw;
use TAW\Tests\TestCase;

/**
 * The `link` field type (ADR-0009 decision 6, data layer Phase 3 Step 3):
 * one JSON value `{url, label, new_tab}`, '' without a URL.
 */
final class LinkFieldTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        $this->meta = [];
        Functions\when('esc_url_raw')->alias(static fn (string $url): string => preg_match('#^(https?:|mailto:|tel:|/|\#)#', $url) ? $url : '');
        Functions\when('sanitize_text_field')->alias(static fn ($v): string => trim(strip_tags((string) $v)));
        Functions\when('wp_json_encode')->alias(static fn ($data, int $flags = 0) => json_encode($data, $flags));
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn ($url): string => htmlspecialchars((string) $url, ENT_QUOTES));
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'page');
        Functions\when('get_post')->alias(static fn ($id) => new \WP_Post(['ID' => (int) $id, 'post_type' => 'page']));
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta[$key] ?? '');
        Functions\when('_doing_it_wrong')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    public function test_sanitizing_stores_json_or_nothing_without_a_url(): void
    {
        $this->assertSame('{"url":"https://a.test/x?y=1","label":"Read more","new_tab":true}', Metabox::sanitizeLinkValue(['url' => ' https://a.test/x?y=1 ', 'label' => '<b>Read more</b>', 'new_tab' => '1']));
        $this->assertSame('{"url":"/contact","label":"","new_tab":false}', Metabox::sanitizeLinkValue('{"url":"/contact","new_tab":"0"}'));
        $this->assertSame('', Metabox::sanitizeLinkValue('{"url":"","label":"Text only"}'), 'no URL, no link');
        $this->assertSame('', Metabox::sanitizeLinkValue(['url' => 'javascript:alert(1)', 'label' => 'x']));
        $this->assertSame('', Metabox::sanitizeLinkValue('not json'));
        $this->assertSame(Metabox::sanitizeLinkValue(['url' => '/a']), Metabox::sanitizeForStorage(['type' => 'link'], ['url' => '/a']));
    }

    public function test_decoding_gives_the_object_or_null(): void
    {
        $this->assertSame(['url' => '/a', 'label' => 'A', 'new_tab' => true], FieldCodec::decode(['type' => 'link'], '{"url":"/a","label":"A","new_tab":true}'));
        $this->assertSame(['url' => '/a', 'label' => '', 'new_tab' => false], FieldCodec::decode(['type' => 'link'], ['url' => '/a']));
        $this->assertNull(FieldCodec::decode(['type' => 'link'], ''));
        $this->assertNull(FieldCodec::decode(['type' => 'link'], '{"label":"x"}'));
        $this->assertContains('link', FieldCodec::STRUCTURED_TYPES);
    }

    public function test_the_link_value(): void
    {
        $this->meta['_taw_cta'] = '{"url":"https://a.test/?a=1&b=2","label":"Go \"now\"","new_tab":true}';
        $this->meta['_taw_plain'] = '{"url":"/about","label":"","new_tab":false}';
        $this->meta['_taw_site'] = 'https://site.test';
        new Metabox(['id' => 'cta_box', 'title' => 'CTA', 'screens' => ['page'], 'fields' => [
            ['id' => 'cta', 'type' => 'link'],
            ['id' => 'plain', 'type' => 'link'],
            ['id' => 'empty', 'type' => 'link'],
            ['id' => 'site', 'type' => 'url'],
        ]]);
        $page = Taw::post(5);

        $cta = $page->field('cta')->link();
        $this->assertSame(['https://a.test/?a=1&b=2', 'Go "now"', true], [$cta->url(), $cta->label(), $cta->newTab()]);
        $this->assertSame('<a href="https://a.test/?a=1&amp;b=2" target="_blank" rel="noopener">Go &quot;now&quot;</a>', (string) $page->field('cta'));
        $this->assertSame('<a href="/about" class="btn">/about</a>', $page->field('plain')->link()->html(['class' => 'btn']), 'no text: the URL');
        $this->assertSame(['url' => '/about', 'label' => '', 'new_tab' => false], $page->field('plain')->value());

        $this->assertFalse($page->field('empty')->link()->exists());
        $this->assertTrue($page->field('empty')->isEmpty());
        $this->assertSame('', (string) $page->field('empty'));
        $this->assertSame('https://site.test', $page->field('site')->link()->url(), 'a url field reads as a link too');
        $this->assertFalse((new Link())->exists());
    }

    public function test_rest_exposes_the_decoded_object(): void
    {
        $restFields = [];
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('register_rest_field')->alias(static function (string $type, string $name, array $args) use (&$restFields): bool {
            $restFields["{$type}:{$name}"] = $args;
            return true;
        });
        Functions\when('apply_filters')->returnArg(2);
        new Metabox(['id' => 'cta_box', 'title' => 'CTA', 'screens' => ['page'], 'fields' => [['id' => 'cta', 'type' => 'link']]]);

        FieldMetaRegistrar::registerPostMeta();

        $this->assertSame(['object', 'null'], $restFields['page:taw_cta']['schema']['type']);
        $this->assertSame(['url', 'label', 'new_tab'], array_keys($restFields['page:taw_cta']['schema']['properties']));
    }

    public function test_the_schema_knows_the_type(): void
    {
        $this->assertSame(['id' => 'cta', 'type' => 'link'], Field::link('cta')->toArray());
        $this->assertContains('link', Field::TYPES);
        $this->assertSame([], Validator::validate(['version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['page'], 'fields' => [['id' => 'cta', 'type' => 'link', 'required' => true]]]));
    }

    public function test_a_required_link_needs_a_url(): void
    {
        Functions\when('__')->returnArg(1);
        $box = new Metabox(['id' => 'cta_box', 'title' => 'CTA', 'screens' => ['page'], 'fields' => []]);
        $field = ['id' => 'cta', 'type' => 'link', 'label' => 'Button', 'required' => true];

        $this->assertSame('Button is required.', $this->callMethod($box, 'validate_field', $field, '{"url":"","label":"Text","new_tab":false}'));
        $this->assertTrue($this->callMethod($box, 'validate_field', $field, '{"url":"/a","label":"","new_tab":false}'));
    }
}
