<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use PHPUnit\Framework\TestCase;
use TAW\Core\Editing\Presets;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Schema;

/**
 * Resolution order (ADR-0005 § 3), lowest first: preset → layer level →
 * individual keys → post type rule → site content map. The constant only
 * replaces the preset.
 */
final class ResolverTest extends TestCase
{
    public function test_no_policy_means_everything_open(): void
    {
        $policy = Resolver::resolve(null);

        $this->assertSame('open', $policy->preset);
        $this->assertSame('default', $policy->presetSource);
        $this->assertSame('taw_unlock_editing', $policy->bypassCapability);
        $this->assertNotContains(false, $policy->site);
        $this->assertNotContains(false, $policy->design);
        $this->assertNotContains(false, $policy->features);
        $this->assertSame(Presets::layer('content', 'open'), $policy->content('page'));
        $this->assertSame([], $policy->warnings);
    }

    public function test_preset_applies_to_every_layer(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('guided'));

        $this->assertSame('definition', $policy->presetSource);
        $this->assertSame(Presets::layer('site', 'guided'), $policy->site);
        $this->assertSame(Presets::layer('design', 'guided'), $policy->design);
        $this->assertSame(Presets::layer('features', 'guided'), $policy->features);
        $this->assertSame(Presets::layer('content', 'guided'), $policy->content('page'));
    }

    public function test_preset_content_applies_to_default_targets_only(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('locked'));

        $this->assertSame('all', $policy->content('page')['lock']);
        $this->assertSame(Presets::layer('content', 'open'), $policy->content('post'));
        $this->assertSame(Presets::layer('content', 'open'), $policy->content('book'));
    }

    public function test_layer_level_replaces_the_preset_for_that_layer(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('locked')->layer('design', 'open'));

        $this->assertSame(Presets::layer('design', 'open'), $policy->design);
        $this->assertSame(Presets::layer('site', 'locked'), $policy->site);
    }

    public function test_individual_keys_override_the_preset(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('guided')->layer('features', ['customHtml' => true]));

        $this->assertTrue($policy->features['customHtml']);
        $this->assertFalse($policy->features['codeEditor']);
    }

    public function test_an_object_can_start_from_its_own_level(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('open')->layer('site', ['level' => 'locked', 'navigation' => true]));

        $this->assertTrue($policy->site['navigation']);
        $this->assertFalse($policy->site['templates']);
        $this->assertFalse($policy->site['siteEditor']);
    }

    public function test_post_type_rule_starts_from_open_for_non_targets(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('locked'), ['event' => ['lock' => 'insert']]);

        $this->assertSame(['allow' => null, 'template' => null, 'lock' => 'insert', 'newPostsOnly' => true], $policy->content('event'));
    }

    public function test_post_type_rule_can_name_a_level(): void
    {
        $policy = Resolver::resolve(null, ['event' => 'structured']);

        $this->assertSame(Presets::layer('content', 'structured'), $policy->content('event'));
    }

    public function test_site_content_map_beats_the_post_type_rule(): void
    {
        $definition = Schema::editing()->content('event', ['lock' => 'all']);
        $policy = Resolver::resolve($definition, ['event' => ['lock' => 'insert', 'template' => [['core/paragraph']]]]);

        $this->assertSame('all', $policy->content('event')['lock']);
        $this->assertSame([['core/paragraph']], $policy->content('event')['template'], 'Keys the site map leaves alone come from the post type rule.');
    }

    public function test_content_layer_level_applies_to_default_targets(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('open')->layer('content', 'locked'));

        $this->assertSame('all', $policy->content('page')['lock']);
        $this->assertFalse($policy->content('post')['lock']);
    }

    public function test_content_after_a_layer_level_keeps_the_level_for_default_targets(): void
    {
        $definition = Schema::editing()->layer('content', 'locked')->content('post', 'guided');
        $policy = Resolver::resolve($definition);

        $this->assertSame('all', $policy->content('page')['lock']);
        $this->assertSame(Presets::layer('content', 'guided'), $policy->content('post'));
    }

    public function test_content_post_types_lists_every_named_type(): void
    {
        $policy = Resolver::resolve(Schema::editing()->content('post', 'guided'), ['event' => 'open']);

        $this->assertSame(['page', 'event', 'post'], $policy->contentPostTypes());
    }

    public function test_constant_replaces_only_the_preset(): void
    {
        $definition = Schema::editing()->preset('open')->layer('design', 'open');
        $policy = Resolver::resolve($definition, [], 'locked');

        $this->assertSame('locked', $policy->preset);
        $this->assertSame('constant', $policy->presetSource);
        $this->assertSame(Presets::layer('site', 'locked'), $policy->site);
        $this->assertSame(Presets::layer('design', 'open'), $policy->design, 'Explicit layer settings still apply.');
    }

    public function test_invalid_constant_is_ignored_with_a_warning(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('guided'), [], 'strict');

        $this->assertSame('guided', $policy->preset);
        $this->assertSame('definition', $policy->presetSource);
        $this->assertSame(['TAW_EDITING_PRESET must be one of open, guided, structured, locked; ignoring "strict".'], $policy->warnings);
    }

    public function test_non_string_constant_is_ignored_with_a_warning(): void
    {
        $policy = Resolver::resolve(null, [], true);

        $this->assertSame('open', $policy->preset);
        $this->assertStringContainsString('ignoring boolean', $policy->warnings[0]);
    }

    public function test_bypass_capability_comes_from_the_definition(): void
    {
        $this->assertSame('manage_layouts', Resolver::resolve(Schema::editing()->bypass('manage_layouts'))->bypassCapability);
    }

    public function test_unknown_override_keys_are_ignored_not_merged(): void
    {
        // Rules rejects these on add(); the resolver must not leak them either.
        $policy = Resolver::resolve((new EditingPolicy('site'))->layer('design', ['madeUp' => false]));

        $this->assertArrayNotHasKey('madeUp', $policy->design);
    }

    public function test_theme_blocks_join_the_curated_list_at_every_level_that_has_one(): void
    {
        foreach (['guided', 'structured', 'locked'] as $level) {
            $policy = Resolver::resolve(Schema::editing()->preset($level)->themeBlocks('taw-gutenberg/*'));

            $this->assertSame([...Presets::CURATED_BLOCKS, 'taw-gutenberg/*'], $policy->content('page')['allow'], $level);
            $this->assertSame(['taw-gutenberg/*'], $policy->themeBlocks);
        }
    }

    public function test_theme_blocks_leave_every_block_allowed_where_nothing_is_restricted(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('open')->themeBlocks('taw-gutenberg/*'));

        $this->assertNull($policy->content('page')['allow']);
        $this->assertNull($policy->content('post')['allow'], 'post types the policy never names stay open');
    }

    public function test_theme_blocks_join_custom_allow_lists_once(): void
    {
        $definition = Schema::editing()
            ->preset('guided')
            ->themeBlocks('acme/*', 'core/heading')
            ->content('book', ['allow' => ['core/heading', 'core/paragraph']]);

        $policy = Resolver::resolve($definition, ['event' => ['allow' => ['core/image']]]);

        $this->assertSame(['core/heading', 'core/paragraph', 'acme/*'], $policy->content('book')['allow']);
        $this->assertSame(['core/image', 'acme/*', 'core/heading'], $policy->content('event')['allow']);
    }

    public function test_theme_blocks_survive_the_preset_constant(): void
    {
        $policy = Resolver::resolve(Schema::editing()->preset('open')->themeBlocks('acme/*'), [], 'structured');

        $this->assertContains('acme/*', $policy->content('page')['allow']);
    }

    public function test_to_array_exposes_everything(): void
    {
        $array = Resolver::resolve(Schema::editing()->preset('guided'))->toArray();

        $this->assertSame(['preset', 'presetSource', 'bypass', 'themeBlocks', 'site', 'design', 'features', 'content', 'warnings'], array_keys($array));
        $this->assertSame(['page'], array_keys($array['content']));
    }
}
