<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use TAW\CLI\WpCliCommand;
use TAW\CLI\WpLoader;

/**
 * `bin/taw wp` under Local by Flywheel: the socket reaches both PHP and
 * the mysql client, and a stopped site is told apart from "not Local".
 */
final class WpCliCommandTest extends TestCase
{
    private string $home = '';

    private string|false $realHome = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realHome = getenv('HOME');
        $this->home = sys_get_temp_dir() . '/taw-wpcli-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/Library/Application Support/Local/run/abc123/mysql', 0777, true);
        mkdir($this->home . '/Local Sites/demo/app/public/wp-content/themes/demo', 0777, true);
        file_put_contents($this->home . '/Library/Application Support/Local/sites.json', json_encode([
            'abc123' => ['id' => 'abc123', 'path' => '~/Local Sites/demo'],
        ]));
        putenv('HOME=' . $this->home);
    }

    protected function tearDown(): void
    {
        putenv($this->realHome === false ? 'HOME' : 'HOME=' . $this->realHome);
        exec('rm -rf ' . escapeshellarg($this->home));
        parent::tearDown();
    }

    private function theme(): string
    {
        return $this->home . '/Local Sites/demo/app/public/wp-content/themes/demo';
    }

    public function test_the_socket_reaches_php_and_the_mysql_client(): void
    {
        [$command, $env] = WpCliCommand::processSpec('/usr/local/bin/wp', '/site', '/run/x.sock', ['db', 'query', 'SELECT 1']);

        $this->assertSame(
            [PHP_BINARY, '-d', 'mysqli.default_socket=/run/x.sock', '-d', 'pdo_mysql.default_socket=/run/x.sock', '/usr/local/bin/wp', '--path=/site', 'db', 'query', 'SELECT 1'],
            $command,
        );
        $this->assertSame(['MYSQL_UNIX_PORT' => '/run/x.sock'], $env, 'wp db starts mysql, which ignores PHP ini settings');
    }

    public function test_without_a_socket_wp_runs_as_is(): void
    {
        $this->assertSame(
            [['/usr/local/bin/wp', '--path=/site', 'option', 'get', 'siteurl'], []],
            WpCliCommand::processSpec('/usr/local/bin/wp', '/site', null, ['option', 'get', 'siteurl']),
        );
    }

    public function test_a_stopped_local_site_is_told_apart_from_no_local_site(): void
    {
        $socket = $this->home . '/Library/Application Support/Local/run/abc123/mysql/mysqld.sock';

        $this->assertSame($socket, WpLoader::expectedLocalSocket($this->theme()));
        $this->assertNull(WpLoader::resolveLocalSocket($this->theme()), 'stopped: no socket yet');
        $this->assertNull(WpLoader::expectedLocalSocket($this->home), 'not inside a Local site');

        touch($socket);
        $this->assertSame($socket, WpLoader::resolveLocalSocket($this->theme()), 'running');
    }
}
