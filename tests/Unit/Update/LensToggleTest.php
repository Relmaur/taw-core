<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TAW\Update\Migrations\LensToggle;

/**
 * Issue #221: the 1.101.0/lens-toggle migration adds "Enable Lens" next to "Enable CSS Studio" in a theme's
 * inc/options.php, only where the file still has the scaffold's shape.
 */
final class LensToggleTest extends TestCase
{
    /** taw-theme's inc/options.php before Lens (the lines that matter, as the scaffold writes them). */
    private const SCAFFOLD = <<<'PHP'
        <?php

        use TAW\Core\OptionsPage\OptionsPage;

        new OptionsPage([
            'id'         => 'taw_settings',
            'fields'     => [
                ['id' => 'company_name',    'label' => __('Company Name', 'taw-theme'),    'type' => 'text',     'width' => '33.33'],
                ['id' => 'css_studio_enabled', 'label' => __('Enable CSS Studio', 'taw-theme'), 'type' => 'checkbox'],
            ],
            'tabs' => [
                ['id' => 'general', 'label' => __('General', 'taw-theme'), 'fields' => ['company_name']],
                ['id' => 'devtools', 'label' => __('Developer Tools', 'taw-theme'), 'fields' => ['css_studio_enabled']],
            ],
        ]);

        PHP;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-lens-toggle-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/inc', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function options(string $code): void
    {
        file_put_contents($this->dir . '/' . LensToggle::FILE, $code);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->dir . '/' . LensToggle::FILE);
    }

    public function test_it_adds_the_toggle_after_css_studio_and_to_the_developer_tools_tab(): void
    {
        $this->options(self::SCAFFOLD);
        $m = new LensToggle();

        $this->assertTrue($m->pending($this->dir));
        $result = $m->run($this->dir);

        $this->assertSame([LensToggle::FILE], $result->changed);
        $this->assertSame([], $result->manual);
        $code = $this->read();
        $this->assertStringContainsString(
            "        ['id' => 'css_studio_enabled', 'label' => __('Enable CSS Studio', 'taw-theme'), 'type' => 'checkbox'],\n"
            . "        ['id' => 'lens_enabled', 'label' => __('Enable Lens', 'taw-theme'), 'type' => 'checkbox'],\n",
            $code
        );
        $this->assertStringContainsString("'fields' => ['css_studio_enabled', 'lens_enabled']]", $code);
        \PhpToken::tokenize($code, TOKEN_PARSE);

        $this->assertFalse($m->pending($this->dir), 'a second run does nothing');
        $this->assertSame([], $m->run($this->dir)->changed);
    }

    public function test_the_real_taw_theme_scaffold_takes_it(): void
    {
        $scaffold = dirname(__DIR__, 4) . '/taw-theme/inc/options.php';
        if (!is_file($scaffold)) {
            $this->markTestSkipped('taw-theme is not beside taw-core (umbrella checkout only)');
        }
        $code = (string) file_get_contents($scaffold);
        if (str_contains($code, "'lens_enabled'")) {
            // The scaffold has it already: the migration must leave such a theme alone.
            $this->options($code);
            $this->assertFalse((new LensToggle())->pending($this->dir));

            return;
        }
        $this->assertNotNull(LensToggle::withToggle($code));
    }

    public function test_it_keeps_the_sites_text_domain_and_other_devtools_fields(): void
    {
        $code = str_replace(
            ["'taw-theme'", "'fields' => ['css_studio_enabled']"],
            ["'acme'", "'fields' => ['css_studio_enabled', 'debug_bar',]"],
            self::SCAFFOLD
        );

        $updated = (string) LensToggle::withToggle($code);

        $this->assertStringContainsString("__('Enable Lens', 'acme')", $updated);
        $this->assertStringContainsString("'fields' => ['css_studio_enabled', 'debug_bar', 'lens_enabled']]", $updated);
    }

    public function test_a_page_without_tabs_only_gets_the_field(): void
    {
        $code = (string) preg_replace("/    'tabs' => \[.*?\n    \],\n/s", '', self::SCAFFOLD);
        $this->assertStringNotContainsString("'tabs'", $code);

        $updated = (string) LensToggle::withToggle($code);

        $this->assertStringContainsString("'id' => 'lens_enabled'", $updated);
    }

    public function test_a_theme_without_css_studio_has_nothing_to_do(): void
    {
        $this->options(str_replace("'css_studio_enabled'", "'company_email'", self::SCAFFOLD));
        $this->assertFalse((new LensToggle())->pending($this->dir));

        unlink($this->dir . '/' . LensToggle::FILE);
        rmdir($this->dir . '/inc');
        $this->assertFalse((new LensToggle())->pending($this->dir), 'no inc/options.php at all');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function editedShapes(): iterable
    {
        yield 'the field split over several lines' => [
            "['id' => 'css_studio_enabled', 'label' => __('Enable CSS Studio', 'taw-theme'), 'type' => 'checkbox'],",
            "[\n            'id' => 'css_studio_enabled',\n            'type' => 'checkbox',\n        ],",
        ];
        yield 'the tab split over several lines' => [
            "['id' => 'devtools', 'label' => __('Developer Tools', 'taw-theme'), 'fields' => ['css_studio_enabled']],",
            "[\n            'id' => 'devtools',\n            'fields' => ['css_studio_enabled'],\n        ],",
        ];
        yield 'the field moved to another tab' => [
            "['id' => 'devtools', 'label' => __('Developer Tools', 'taw-theme'), 'fields' => ['css_studio_enabled']],",
            "['id' => 'devtools', 'label' => __('Developer Tools', 'taw-theme'), 'fields' => []],",
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editedShapes')]
    public function test_an_edited_file_gets_a_manual_step_and_no_write(string $from, string $to): void
    {
        $code = str_replace($from, $to, self::SCAFFOLD);
        $this->assertNotSame(self::SCAFFOLD, $code);
        $this->options($code);

        $result = (new LensToggle())->run($this->dir);

        $this->assertSame([], $result->changed);
        $this->assertCount(1, $result->manual);
        $this->assertStringContainsString('taw upgrade --explain 1.101.0/lens-toggle', $result->manual[0]);
        $this->assertSame($code, $this->read());
    }
}
