<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TAW\Core\Editing\Rules;

final class RulesTest extends TestCase
{
    public function test_a_complete_policy_is_valid(): void
    {
        $this->assertSame([], Rules::validatePolicy([
            'preset' => 'guided',
            'bypass' => ['capability' => 'taw_unlock_editing'],
            'layers' => [
                'site'     => ['level' => 'structured', 'navigation' => true],
                'design'   => 'locked',
                'features' => ['customHtml' => true],
                'content'  => [
                    'page'  => ['allow' => ['core/*', 'taw-gutenberg/hero'], 'lock' => 'all', 'template' => [['core/heading', ['level' => 2]]]],
                    'post'  => 'open',
                    'event' => ['allow' => null, 'lock' => false, 'newPostsOnly' => false],
                ],
            ],
        ]));
    }

    public function test_the_content_layer_can_be_a_level(): void
    {
        $this->assertSame([], Rules::validatePolicy(['layers' => ['content' => 'structured']]));
    }

    public function test_an_empty_policy_is_valid(): void
    {
        $this->assertSame([], Rules::validatePolicy([]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPolicies(): array
    {
        return [
            'unknown preset'         => [['preset' => 'strict'], '/preset: must be one of open, guided, structured, locked'],
            'bypass not an object'   => [['bypass' => 'admin'], '/bypass: must be an object'],
            'bypass bad capability'  => [['bypass' => ['capability' => 'Edit Things']], '/bypass/capability: must be a capability name'],
            'bypass unknown key'     => [['bypass' => ['users' => ['marco']]], '/bypass/users: unknown key'],
            'layers not an object'   => [['layers' => ['guided']], '/layers: must be an object'],
            'unknown layer'          => [['layers' => ['widgets' => 'open']], '/layers/widgets: unknown layer'],
            'unknown layer level'    => [['layers' => ['design' => 'strict']], '/layers/design: must be one of'],
            'unknown layer setting'  => [['layers' => ['design' => ['customColours' => false]]], '/layers/design/customColours: unknown key'],
            'setting from wrong layer' => [['layers' => ['site' => ['codeEditor' => false]]], '/layers/site/codeEditor: unknown key'],
            'setting not a boolean'  => [['layers' => ['features' => ['codeEditor' => 'no']]], '/layers/features/codeEditor: must be true (allowed) or false (locked)'],
            'bad level in object'    => [['layers' => ['site' => ['level' => 'max']]], '/layers/site/level: must be one of'],
            'bad post type key'      => [['layers' => ['content' => ['Not A Type' => 'open']]], '/layers/content/Not A Type: not a valid post type key'],
            'bad content level'      => [['layers' => ['content' => ['page' => 'strict']]], '/layers/content/page: must be one of'],
            'content rule not object' => [['layers' => ['content' => ['page' => 3]]], '/layers/content/page: must be a level name or an object'],
            'allow not a list'       => [['layers' => ['content' => ['page' => ['allow' => 'core/*']]]], '/layers/content/page/allow: must be a list'],
            'bad allow glob'         => [['layers' => ['content' => ['page' => ['allow' => ['core/*/x']]]]], '/layers/content/page/allow/0: must be a block name or glob'],
            'bad lock'               => [['layers' => ['content' => ['page' => ['lock' => 'everything']]]], '/layers/content/page/lock: must be false or one of insert, contentOnly, all'],
            'lock true'              => [['layers' => ['content' => ['page' => ['lock' => true]]]], '/layers/content/page/lock: must be false or one of'],
            'newPostsOnly not bool'  => [['layers' => ['content' => ['page' => ['newPostsOnly' => 'yes']]]], '/layers/content/page/newPostsOnly: must be true or false'],
            'template not a list'    => [['layers' => ['content' => ['page' => ['template' => ['core/heading' => []]]]]], '/layers/content/page/template: must be a list'],
            'template bad name'      => [['layers' => ['content' => ['page' => ['template' => [['heading']]]]]], '/layers/content/page/template/0/0: must be a block name'],
            'template bad attrs'     => [['layers' => ['content' => ['page' => ['template' => [['core/heading', 'big']]]]]], '/layers/content/page/template/0/1: block attributes must be an object'],
            'template bad inner'     => [['layers' => ['content' => ['page' => ['template' => [['core/group', [], [['x']]]]]]]], '/layers/content/page/template/0/2/0/0: must be a block name'],
            'allowBound is reserved' => [['layers' => ['content' => ['page' => ['allowBound' => true]]]], '/layers/content/page/allowBound: is reserved for Block Bindings'],
        ];
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[DataProvider('invalidPolicies')]
    public function test_invalid_policies_point_at_the_problem(array $policy, string $expected): void
    {
        $errors = Rules::validatePolicy($policy);

        $this->assertNotEmpty($errors);
        $this->assertStringStartsWith($expected, $errors[0], implode("\n", $errors));
    }

    public function test_a_content_rule_uses_the_pointer_it_is_given(): void
    {
        $this->assertSame(['/editing/lock: must be false or one of insert, contentOnly, all'], Rules::validateContentRule(['lock' => 'x'], '/editing'));
        $this->assertSame([], Rules::validateContentRule('locked', '/editing'));
    }
}
