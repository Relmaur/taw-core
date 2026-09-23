<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Core\Editing\Bypass;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class BypassTest extends TestCase
{
    public function test_users_can_be_a_list_or_a_comma_separated_string(): void
    {
        $this->assertSame(['marco', 'dev'], Bypass::parseUsers(['marco', ' dev ', '', 'marco', 3]));
        $this->assertSame(['marco', 'dev'], Bypass::parseUsers('marco, dev,'));
        $this->assertSame([], Bypass::parseUsers(null));
        $this->assertSame([], Bypass::parseUsers(true));
    }

    public function test_named_users_are_granted_the_capability(): void
    {
        $bypass = new Bypass('taw_unlock_editing', ['marco']);
        $marco = new \WP_User(1);
        $marco->user_login = 'marco';
        $client = new \WP_User(2);
        $client->user_login = 'client';

        $this->assertSame(['taw_unlock_editing' => true], $bypass->grant([], ['taw_unlock_editing'], [], $marco));
        $this->assertSame([], $bypass->grant([], ['taw_unlock_editing'], [], $client), 'Not named: nothing granted, whatever the role.');
        $this->assertSame([], $bypass->grant([], ['edit_posts'], [], $marco), 'Only the bypass capability is granted.');
        $this->assertSame([], $bypass->grant([], ['taw_unlock_editing'], [], null));
    }

    public function test_active_asks_for_the_capability(): void
    {
        Functions\expect('current_user_can')->once()->with('manage_layouts')->andReturn(true);

        $this->assertTrue((new Bypass('manage_layouts', []))->active());
    }

    public function test_register_hooks_user_has_cap(): void
    {
        $bypass = new Bypass('taw_unlock_editing', []);
        $bypass->register();

        $this->assertSame(10, has_filter('user_has_cap', [$bypass, 'grant']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_from_policy_reads_the_constant_and_capability(): void
    {
        define('TAW_EDITING_BYPASS_USERS', ['marco']);

        $bypass = Bypass::fromPolicy(Resolver::resolve(Schema::editing()->bypass('manage_layouts')));

        $this->assertSame('manage_layouts', $bypass->capability);
        $this->assertSame(['marco'], $bypass->users);
    }
}
