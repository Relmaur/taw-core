<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\JsonLoader;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Core\Schema\Validator;

/**
 * The editing policy as a schema definition (ADR-0005): the PHP builder,
 * post types' own rules, the registry, and the JSON kind.
 */
final class EditingDefinitionTest extends SchemaTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/valid';

    public function test_builder_produces_the_json_shape(): void
    {
        $policy = Schema::editing()
            ->preset('structured')
            ->bypass('manage_layouts')
            ->layer('design', 'guided')
            ->content('page', ['lock' => 'all']);

        $this->assertSame('editing:site', $policy->qualifiedKey());
        $this->assertSame([
            'preset' => 'structured',
            'bypass' => ['capability' => 'manage_layouts'],
            'layers' => ['design' => 'guided', 'content' => ['page' => ['lock' => 'all']]],
        ], $policy->toArray());
        $this->assertSame([], $policy->problems());
    }

    public function test_an_empty_policy_has_an_empty_shape(): void
    {
        $this->assertSame([], Schema::editing()->toArray());
    }

    public function test_the_key_must_be_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a site has one editing policy');

        new EditingPolicy('pages');
    }

    public function test_content_after_a_layer_level_keeps_the_level_on_default_targets(): void
    {
        $policy = Schema::editing()->layer('content', 'locked')->content('post', 'open');

        $this->assertSame(['page' => 'locked', 'post' => 'open'], $policy->layers()['content']);
    }

    public function test_problems_are_reported_with_pointers(): void
    {
        $problems = Schema::editing()->preset('strict')->layer('features', ['codeEditor' => 'no'])->problems();

        $this->assertSame([
            'Editing policy /preset: must be one of open, guided, structured, locked',
            'Editing policy /layers/features/codeEditor: must be true (allowed) or false (locked)',
        ], $problems);
    }

    public function test_post_type_editing_rule_never_reaches_register_post_type(): void
    {
        $postType = Schema::postType('event')->labels('Event', 'Events')->editing(['lock' => 'insert']);

        $this->assertSame(['lock' => 'insert'], $postType->editingRule());
        $this->assertArrayNotHasKey('editing', $postType->toArray());
        $this->assertSame([], $postType->problems());
    }

    public function test_post_type_editing_rule_is_validated(): void
    {
        $this->assertSame(
            ['Post type "event" /editing/lock: must be false or one of insert, contentOnly, all'],
            Schema::postType('event')->editing(['lock' => 'yes'])->problems()
        );
        $this->assertSame([], Schema::postType('event')->problems(), 'No rule, no problems.');
    }

    public function test_registry_holds_one_policy_and_refuses_an_invalid_one(): void
    {
        $registry = Registry::instance();

        $this->assertNull($registry->editing());
        $this->assertFalse($registry->add(Schema::editing()->preset('strict')));
        $this->assertNull($registry->editing());
        $this->assertSame(['Editing policy /preset: must be one of open, guided, structured, locked (php)'], $this->notices);

        $this->assertTrue($registry->add(Schema::editing()->preset('guided')));
        $this->assertSame('guided', $registry->editing()?->presetLevel());
    }

    public function test_resolver_reads_the_registry(): void
    {
        $registry = Registry::instance();
        $registry->add(Schema::editing()->preset('guided'));
        $registry->add(Schema::postType('event')->editing('locked'));
        $registry->add(Schema::postType('book'));

        $policy = Resolver::fromRegistry($registry);

        $this->assertSame('guided', $policy->preset);
        $this->assertSame('all', $policy->content('event')['lock']);
        $this->assertSame(['page', 'event'], $policy->contentPostTypes());
    }

    public function test_json_editing_file_becomes_the_same_definition(): void
    {
        $read = JsonLoader::readFile(self::FIXTURES . '/editing.json');
        $this->assertSame([], $read['errors']);

        $definition = JsonLoader::toDefinition($read['data']);
        $this->assertInstanceOf(EditingPolicy::class, $definition);
        $this->assertSame('structured', $definition->presetLevel());
        $this->assertSame('manage_layouts', $definition->bypassCapability());

        $policy = Resolver::resolve($definition);
        $this->assertSame('contentOnly', $policy->content('page')['lock']);
        $this->assertSame(['core/*', 'taw-gutenberg/*'], $policy->content('page')['allow']);
        $this->assertSame([['core/heading', ['level' => 1]], ['core/group', [], [['core/paragraph']]]], $policy->content('page')['template']);
        $this->assertFalse($policy->content('post')['lock']);
        $this->assertTrue($policy->features['customHtml']);
        $this->assertFalse($policy->features['codeEditor']);
        $this->assertTrue($policy->site['navigation']);
        $this->assertFalse($policy->site['siteEditor']);
        $this->assertSame(\TAW\Core\Editing\Presets::layer('design', 'guided'), $policy->design);
    }

    public function test_json_post_type_editing_key_is_loaded(): void
    {
        $definition = JsonLoader::toDefinition(JsonLoader::readFile(self::FIXTURES . '/post-types/event.json')['data']);

        $this->assertSame(['level' => 'guided', 'lock' => 'insert', 'template' => [['core/paragraph']]], $definition->editingRule());
    }

    public function test_validator_requires_the_site_key(): void
    {
        $this->assertSame(
            ['/key: must be "site" (a site has one editing policy)'],
            Validator::validate(['version' => 1, 'kind' => 'editing', 'key' => 'pages'])
        );
    }

    public function test_validator_checks_the_policy_and_post_type_rules(): void
    {
        $this->assertSame(
            ['/preset: must be one of open, guided, structured, locked'],
            Validator::validate(['version' => 1, 'kind' => 'editing', 'key' => 'site', 'preset' => 'max'])
        );
        $this->assertSame(
            ['/editing/allowBound: is reserved for Block Bindings (data layer Phase 4) and not available yet'],
            Validator::validate(['version' => 1, 'kind' => 'post_type', 'key' => 'event', 'editing' => ['allowBound' => true]])
        );
        $this->assertSame(
            ['/rules: unknown key for an editing (allowed: preset, bypass, layers)'],
            Validator::validate(['version' => 1, 'kind' => 'editing', 'key' => 'site', 'rules' => []])
        );
    }
}
