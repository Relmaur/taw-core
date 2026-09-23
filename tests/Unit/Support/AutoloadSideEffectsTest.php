<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * What happens the moment a consumer runs `require vendor/autoload.php`.
 *
 * Composer loads taw/core's `autoload.files` entries (performance.php,
 * utilities.php) during that require — before any test can run inside this
 * PHPUnit process, whose own bootstrap already required the autoloader. So
 * each scenario runs in a FRESH `php` child process that sets up the world
 * first (a recording add_action(), a predefined dump(), a constant) and only
 * then requires the autoloader, exactly like a real theme's functions.php.
 *
 * Why this matters (ADR-0003): a block theme that installs taw/core only for
 * its data layer must not have Performance::removeBloat() dequeue its block
 * CSS just because the package was loaded.
 */
final class AutoloadSideEffectsTest extends TestCase
{
    public function test_loading_the_autoloader_registers_no_wordpress_hooks(): void
    {
        $result = $this->runAfterAutoload('');

        $this->assertSame([], $result['hooks'], 'performance.php must not register hooks at autoload time');
    }

    public function test_the_autoload_escape_hatch_restores_load_time_registration(): void
    {
        $result = $this->runAfterAutoload("define('TAW_PERFORMANCE_AUTOLOAD', true);");

        $this->assertContains('wp_enqueue_scripts:TAW\Support\Performance::removeBloat', $result['hooks']);
        $this->assertContains('wp_head:TAW\Support\Performance::renderPreconnects', $result['hooks']);
    }

    public function test_the_escape_hatch_set_to_false_registers_nothing(): void
    {
        $result = $this->runAfterAutoload("define('TAW_PERFORMANCE_AUTOLOAD', false);");

        $this->assertSame([], $result['hooks']);
    }

    public function test_a_dump_function_defined_elsewhere_does_not_cause_a_redeclare_fatal(): void
    {
        // Another library (Symfony VarDumper, Laravel, Ray…) got there first.
        $result = $this->runAfterAutoload("function dump(\$v, string \$l = ''): void { echo 'theirs'; } function dd(\$v, string \$l = ''): void {}");

        $this->assertTrue($result['loaded'], 'autoloader must load cleanly when dump()/dd() already exist');
        $this->assertSame('theirs', $result['dump_output'], 'the pre-existing dump() must be the one that runs');
    }

    public function test_taw_dump_helpers_are_defined_when_nothing_else_defines_them(): void
    {
        $result = $this->runAfterAutoload('');

        $this->assertTrue($result['dump_is_taws']);
    }

    /**
     * Run a child PHP process: $setup, then a recording add_action/add_filter,
     * then `require vendor/autoload.php`. Returns what it observed as JSON.
     *
     * @return array{hooks: list<string>, loaded: bool, dump_output: string, dump_is_taws: bool}
     */
    private function runAfterAutoload(string $setup): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $script = <<<PHP
<?php
define('ABSPATH', '/tmp/');
{$setup}
\$GLOBALS['recorded_hooks'] = [];
function add_action(string \$hook, \$cb, int \$priority = 10, int \$args = 1): bool {
    \$name = is_array(\$cb) ? (is_object(\$cb[0]) ? get_class(\$cb[0]) : \$cb[0]) . '::' . \$cb[1] : (is_string(\$cb) ? \$cb : 'closure');
    \$GLOBALS['recorded_hooks'][] = \$hook . ':' . \$name;
    return true;
}
function add_filter(string \$hook, \$cb, int \$priority = 10, int \$args = 1): bool {
    return add_action(\$hook, \$cb, \$priority, \$args);
}
require '{$autoload}';
ob_start();
dump('x');
\$dumpOutput = (string) ob_get_clean();
\$reflection = new ReflectionFunction('dump');
echo json_encode([
    'hooks'        => \$GLOBALS['recorded_hooks'],
    'loaded'       => true,
    'dump_output'  => \$dumpOutput,
    'dump_is_taws' => str_ends_with((string) \$reflection->getFileName(), 'src/Support/utilities.php'),
]);
PHP;

        $file = tempnam(sys_get_temp_dir(), 'taw-autoload-');
        $this->assertNotFalse($file);
        file_put_contents($file, $script);

        try {
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        } finally {
            unlink($file);
        }

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded, "child process failed:\n" . $output);

        return $decoded;
    }
}
