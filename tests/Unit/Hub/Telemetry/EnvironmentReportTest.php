<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Telemetry;

use Brain\Monkey\Functions;
use TAW\Hub\Telemetry\EnvironmentReport;
use TAW\Tests\TestCase;

final class EnvironmentReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_bloginfo')->justReturn('6.7.1');
        Functions\when('wp_get_environment_type')->justReturn('staging');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_using_ext_object_cache')->justReturn(true);

        $GLOBALS['wpdb'] = new class {
            public function db_version(): string
            {
                return '8.0.36';
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_it_collects_the_expected_shape(): void
    {
        $report = EnvironmentReport::collect();

        $this->assertSame('6.7.1', $report['wordpress']);
        $this->assertSame('8.0.36', $report['mysql']);
        $this->assertSame('staging', $report['environment']);
        $this->assertFalse($report['multisite']);
        $this->assertTrue($report['object_cache']);
        $this->assertSame(PHP_VERSION, $report['php']);
        $this->assertIsBool($report['ext_sodium']);
        $this->assertIsString($report['taw_core']);
        $this->assertIsInt($report['server_time']);
    }

    public function test_mysql_is_null_when_wpdb_is_unavailable(): void
    {
        unset($GLOBALS['wpdb']);

        $this->assertNull(EnvironmentReport::collect()['mysql']);
    }
}
