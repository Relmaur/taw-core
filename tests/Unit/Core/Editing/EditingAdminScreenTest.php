<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use TAW\Core\Editing\Bypass;
use TAW\Core\Editing\EditingAdminScreen;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class EditingAdminScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        Functions\when('current_user_can')->justReturn(false);
    }

    /**
     * @param list<string> $users
     */
    private function screen(EditingPolicy|null $definition, array $users = [], mixed $constant = null): EditingAdminScreen
    {
        $policy = Resolver::resolve($definition, [], $constant);

        return new EditingAdminScreen($policy, new Bypass($policy->bypassCapability, $users), static fn (): array => ['core/paragraph', 'core/heading', 'acme/hero']);
    }

    public function test_report_shows_the_preset_its_source_and_every_layer(): void
    {
        $report = $this->screen(Schema::editing()->preset('guided'), ['marco'])->report('json:/theme/taw-schema/editing.json');

        $this->assertSame('guided', $report['preset']);
        $this->assertSame('json:/theme/taw-schema/editing.json', $report['presetSource']);
        $this->assertFalse($report['youBypass']);
        $this->assertSame(['capability' => 'taw_unlock_editing', 'users' => ['marco']], $report['bypass']);
        $this->assertSame(['site', 'design', 'features'], array_keys($report['layers']));
        $this->assertSame([], $report['themeBlocks']);
        $this->assertSame(['page'], array_keys($report['content']));
        $this->assertSame([], $report['warnings']);
    }

    public function test_constant_is_named_as_the_source(): void
    {
        $report = $this->screen(Schema::editing()->preset('guided'), ['marco'], 'locked')->report('json:/x.json');

        $this->assertSame('locked', $report['preset']);
        $this->assertSame('TAW_EDITING_PRESET (wp-config.php)', $report['presetSource']);
    }

    public function test_warns_when_something_is_locked_but_nobody_is_named(): void
    {
        $warnings = $this->screen(Schema::editing()->preset('guided'))->report(null)['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('TAW_EDITING_BYPASS_USERS is empty', $warnings[0]);
    }

    public function test_no_bypass_warning_when_nothing_is_locked(): void
    {
        $this->assertSame([], $this->screen(null)->report(null)['warnings']);
    }

    public function test_warns_about_allow_patterns_that_match_no_block(): void
    {
        $definition = Schema::editing()->content('page', ['allow' => ['core/heading', 'taw-gutenberg/*']]);

        $warnings = $this->screen($definition, ['marco'])->report(null)['warnings'];

        $this->assertSame(['"taw-gutenberg/*" in the page allow list matches no registered block.'], $warnings);
    }

    public function test_warns_about_allow_bound_patterns_that_match_nothing_or_do_nothing(): void
    {
        $definition = Schema::editing()
            ->content('page', ['allow' => ['core/heading'], 'allowBound' => ['core/heading', 'ghost/*']])
            ->content('post', ['allowBound' => ['core/heading']]);

        $warnings = $this->screen($definition, ['marco'])->report(null)['warnings'];

        $this->assertSame([
            '"ghost/*" in the page allowBound list matches no registered block.',
            'The post rule allows every block, so its allowBound list has no effect.',
        ], $warnings);
    }

    public function test_theme_blocks_are_shown_and_checked_once(): void
    {
        $definition = Schema::editing()->preset('guided')->themeBlocks('acme/*', 'ghost/*')->content('book', ['allow' => ['core/heading']]);

        $report = $this->screen($definition, ['marco'])->report(null);

        $this->assertSame(['acme/*', 'ghost/*'], $report['themeBlocks']);
        $this->assertContains('acme/*', $report['content']['page']['allow']);
        $this->assertSame(['"ghost/*" in themeBlocks matches no registered block.'], $report['warnings'], 'once, not per post type');
    }

    public function test_resolver_warnings_are_shown(): void
    {
        $warnings = $this->screen(null, ['marco'], 'strict')->report(null)['warnings'];

        $this->assertStringContainsString('TAW_EDITING_PRESET must be one of', $warnings[0]);
    }

    public function test_page_is_under_tools_for_admins(): void
    {
        Functions\expect('add_management_page')->once()->with('TAW Editing', 'TAW Editing', 'manage_options', 'taw-editing', \Mockery::type('array'));

        $screen = $this->screen(null);
        $screen->register();
        $this->assertSame(10, has_action('admin_menu', [$screen, 'addPage']));
        $screen->addPage();
    }
}
