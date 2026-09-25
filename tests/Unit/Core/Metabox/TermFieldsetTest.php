<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Metabox\Store\PostMetaStore;
use TAW\Core\Metabox\Store\TermMetaStore;
use TAW\Core\Metabox\Store\UserMetaStore;
use TAW\Tests\TestCase;

/**
 * ADR-0008 decisions 4–5: storage contexts and `term:<taxonomy>` targets.
 * The post path is guarded separately by the rendered-HTML and save
 * fixtures (byte-identical before/after, checked on the taw site).
 */
final class TermFieldsetTest extends TestCase
{
    /** @var array<string, mixed> "id|key" → value */
    private array $termMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'book'], true));
        Functions\when('get_term_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->termMeta["{$id}|{$key}"] ?? '');
        Functions\when('update_term_meta')->alias(function (int $id, string $key, $value): bool {
            $this->termMeta["{$id}|{$key}"] = $value;
            return true;
        });
        Functions\when('delete_term_meta')->alias(function (int $id, string $key): bool {
            unset($this->termMeta["{$id}|{$key}"]);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @param list<string> $screens
     * @param list<array<string, mixed>> $fields
     */
    private function box(array $screens, array $fields = []): Metabox
    {
        return new Metabox(['id' => 'genre_details', 'title' => 'Genre', 'screens' => $screens, 'fields' => $fields ?: [
            ['id' => 'genre_tagline', 'type' => 'text'],
            ['id' => 'genre_featured', 'type' => 'checkbox'],
            ['id' => 'genre_blurb', 'type' => 'text', 'conditions' => [['id' => 'genre_featured', 'value' => '1']]],
            ['id' => 'genre_code', 'type' => 'text', 'readonly' => true],
        ]]);
    }

    public function test_stores_use_their_object_types_meta_functions(): void
    {
        Functions\expect('get_post_meta')->once()->with(3, 'k', true)->andReturn('p');
        Functions\expect('get_user_meta')->once()->with(4, 'k', true)->andReturn('u');
        Functions\expect('update_user_meta')->once()->with(4, 'k', 'v');
        Functions\expect('delete_post_meta')->once()->with(3, 'k');

        $this->assertSame('p', (new PostMetaStore())->get(3, 'k'));
        $this->assertSame('u', (new UserMetaStore())->get(4, 'k'));
        (new UserMetaStore())->set(4, 'k', 'v');
        (new PostMetaStore())->delete(3, 'k');
        (new TermMetaStore())->set(5, 'k', 'v');

        $this->assertSame('v', $this->termMeta['5|k']);
        $this->assertSame(['post', 'term', 'user'], [(new PostMetaStore())->objectType(), (new TermMetaStore())->objectType(), (new UserMetaStore())->objectType()]);
    }

    public function test_term_targets_never_count_as_post_screens(): void
    {
        $box = $this->box(['book', 'term:genre']);

        $this->assertSame(['genre'], $box->termTaxonomies());
        $this->assertSame(['book'], Metabox::screensToPostTypes(['book', 'term:genre']), 'not "page", as a slug would');
        $this->assertSame(['book'], Metabox::postTypesWithMetabox());
        $this->assertSame(['_taw_genre_tagline', '_taw_genre_featured', '_taw_genre_blurb', '_taw_genre_code'], array_keys(Metabox::fieldsFor('term', 'genre')));
        $this->assertSame([], Metabox::fieldsFor('post', 'page'));
        $this->assertSame([], Metabox::fieldsFor('term', 'category'));
    }

    public function test_term_screens_are_hooked_only_for_term_targets(): void
    {
        $postOnly = new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['book'], 'fields' => []]);
        $this->assertFalse(has_action('genre_add_form_fields', [$postOnly, 'render_term_add']));

        $box = $this->box(['term:genre']);
        $this->assertSame(10, has_action('genre_add_form_fields', [$box, 'render_term_add']));
        $this->assertSame(10, has_action('genre_edit_form_fields', [$box, 'render_term_edit']));
        $this->assertSame(10, has_action('created_genre', [$box, 'save_term']));
        $this->assertSame(10, has_action('edited_genre', [$box, 'save_term']));
    }

    private function postForm(array $values): void
    {
        $_POST = $values + ['genre_details_nonce' => 'n'];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '');
        Functions\when('get_term')->justReturn(new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre']));
    }

    public function test_save_term_sanitizes_and_applies_conditions_and_readonly(): void
    {
        $box = $this->box(['term:genre']);
        $this->termMeta['9|_taw_genre_blurb'] = 'stale';
        $this->termMeta['9|_taw_genre_code'] = 'KEEP';
        $this->postForm(['_taw_genre_tagline' => ' <b>Epic</b> ', '_taw_genre_blurb' => 'x', '_taw_genre_code' => 'HACK']);
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id): bool => $cap === 'edit_term' && $id === 9);

        $box->save_term(9);

        $this->assertSame([
            '9|_taw_genre_code'    => 'KEEP',
            '9|_taw_genre_tagline' => 'Epic',
        ], (static function (array $m): array { ksort($m); return $m; })($this->termMeta), 'unchecked checkbox and hidden blurb deleted, read-only untouched');
    }

    public function test_save_term_needs_the_nonce_and_edit_term(): void
    {
        $box = $this->box(['term:genre']);
        $this->postForm(['_taw_genre_tagline' => 'Epic']);

        Functions\when('current_user_can')->justReturn(false);
        $box->save_term(9);
        $this->assertSame([], $this->termMeta);

        Functions\when('current_user_can')->justReturn(true);
        unset($_POST['genre_details_nonce']);
        $box->save_term(9);
        $this->assertSame([], $this->termMeta, 'wp_insert_term() without the form: no nonce, no write');
    }

    public function test_save_term_reports_validation_errors_for_the_edit_screen(): void
    {
        $box = $this->box(['term:genre'], [['id' => 'genre_tagline', 'type' => 'text', 'label' => 'Tagline', 'required' => true]]);
        $this->postForm(['_taw_genre_tagline' => '']);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('__')->returnArg(1);
        $transients = [];
        Functions\when('set_transient')->alias(static function (string $key, $value) use (&$transients): bool {
            $transients[$key] = $value;
            return true;
        });

        $box->save_term(9);

        $this->assertArrayHasKey('taw_validation_errors_term_9', $transients);
    }

    public function test_term_readers_match_the_post_helpers_shapes(): void
    {
        $this->termMeta['9|_taw_genre_featured'] = '1';
        $this->termMeta['9|_taw_genre_awards'] = '[{"name":"Hugo"}]';
        $this->termMeta['9|_taw_genre_related'] = '[4,"5",0]';
        $this->termMeta['9|_taw_genre_tagline'] = 'Epic';

        $genre = Metabox::term(9);

        $this->assertSame('Epic', Metabox::get_term(9, 'genre_tagline'));
        $this->assertTrue($genre->bool('genre_featured'));
        $this->assertSame([['name' => 'Hugo']], $genre->repeater('genre_awards'));
        $this->assertSame([4, 5], array_values($genre->posts('genre_related')));
        $this->assertSame('#000', $genre->color('genre_color', '#000'));
        $this->assertSame([], $genre->gradientText('genre_missing'));
        $this->assertSame('', Metabox::term(0)->get('genre_tagline'));
    }
}
