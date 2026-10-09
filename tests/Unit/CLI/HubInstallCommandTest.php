<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\HubEnrollCommand;
use TAW\CLI\HubInstallCommand;

/**
 * hub:install and hub:enroll were retired with taw-hub; themes' bin/taw may
 * still register them, so they must construct, stay hidden and explain.
 */
final class HubInstallCommandTest extends TestCase
{
    public function test_retired_commands_explain_and_fail(): void
    {
        foreach ([new HubInstallCommand('/tmp/theme'), new HubEnrollCommand('/tmp/theme')] as $command) {
            $this->assertTrue($command->isHidden());
            $tester = new CommandTester($command);
            $code = $tester->execute(['--activate' => true, '--token' => 'x']);
            $this->assertSame(Command::FAILURE, $code);
            $this->assertStringContainsString('retired with taw-hub', $tester->getDisplay());
            $this->assertStringContainsString('taw-fleet live', $tester->getDisplay());
        }
    }
}
