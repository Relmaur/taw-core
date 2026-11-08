<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use TAW\Core\Lens\SourceMap;
use TAW\Core\Lens\TemplateText;
use TAW\Tests\TestCase;

final class SourceMapTest extends TestCase
{
    private string $theme = '';

    protected function setUp(): void
    {
        parent::setUp();
        SourceMap::resetForTests();
        TemplateText::resetForTests();
        $this->theme = sys_get_temp_dir() . '/taw-lens-map-' . uniqid();
        mkdir($this->theme . '/Blocks/LensProbe', 0777, true);
    }

    protected function tearDown(): void
    {
        SourceMap::resetForTests();
        exec('rm -rf ' . escapeshellarg($this->theme));
        parent::tearDown();
    }

    public function testOffItPrintsAndRecordsNothing(): void
    {
        ob_start();
        $id = SourceMap::open('part', 'header', 'Header', null);
        SourceMap::close($id);
        SourceMap::record(['store' => 'post', 'object' => 1, 'key' => 'k', 'type' => 'text', 'label' => 'K', 'value' => 'v', 'admin' => '']);

        self::assertSame('', ob_get_clean());
        self::assertNull($id);
        self::assertNull(SourceMap::openBlock(new \stdClass()));
        self::assertSame([], SourceMap::nodes());
    }

    public function testNodesNestWithMarkersAndReadsLandOnTheOpenNode(): void
    {
        SourceMap::enable();
        $this->read('site', 'root read');

        ob_start();
        $header = SourceMap::open('part', 'header', 'Header', null);
        $this->read('menu', 'in header');
        $hero = SourceMap::open('block', 'hero', 'Hero', null);
        $this->read('hero_title', 'Hello');
        SourceMap::close($hero);
        $this->read('after', 'back in header');
        SourceMap::close($header);
        $html = ob_get_clean();

        self::assertSame('<!--taw-lens:1--><!--taw-lens:2--><!--/taw-lens:2--><!--/taw-lens:1-->', $html);
        $nodes = SourceMap::nodes();
        self::assertSame(['site'], array_column($nodes[0]['reads'], 'key'));
        self::assertSame(['menu', 'after'], array_column($nodes[1]['reads'], 'key'));
        self::assertSame(['hero_title'], array_column($nodes[2]['reads'], 'key'));
        self::assertSame(1, $nodes[2]['parent']);
    }

    public function testClosingANodeClosesWhatItLeftOpenButNeverTheRoot(): void
    {
        SourceMap::enable();
        ob_start();
        $outer = SourceMap::open('part', 'a', 'A', null);
        SourceMap::open('part', 'b', 'B', null);
        SourceMap::close($outer);
        SourceMap::close($outer);
        SourceMap::close(0);
        SourceMap::close(99);

        self::assertSame('<!--taw-lens:1--><!--taw-lens:2--><!--/taw-lens:2--><!--/taw-lens:1-->', ob_get_clean());
    }

    public function testTheLatestReadOfAValueWinsAndQuietReadsAreNotRecorded(): void
    {
        SourceMap::enable();
        $this->read('title', 'First');
        $this->read('title', 'Second');
        SourceMap::quietly(fn () => $this->read('hidden', 'x'));

        self::assertSame(['Second'], array_column(SourceMap::nodes()[0]['reads'], 'value'));
    }

    public function testABlockNodeCarriesItsClassTemplateAndFiles(): void
    {
        $classFile = $this->theme . '/Blocks/LensProbe/LensProbe.php';
        file_put_contents($classFile, '<?php namespace TAW\Tests\Lens; final class LensProbe { public function getId(): string { return "lens-probe"; } }');
        file_put_contents($this->theme . '/Blocks/LensProbe/index.php', "<h2>Probe</h2>\n<p><?php echo esc_html__('Read more', 'taw'); ?></p>\n");
        file_put_contents($this->theme . '/Blocks/LensProbe/style.css', '');
        file_put_contents($this->theme . '/Blocks/LensProbe/.DS_Store', '');
        require_once $classFile;

        SourceMap::enable();
        SourceMap::root($this->theme . '/page.php', 'Home');
        ob_start();
        $id = SourceMap::openBlock(new \TAW\Tests\Lens\LensProbe(), 7);
        SourceMap::close($id);
        ob_end_clean();

        $map = SourceMap::manifest($this->theme);
        $node = $map['nodes']['1'];
        self::assertSame(['block', 'lens-probe', 'Lens probe', 'Blocks/LensProbe/index.php', 7, 'TAW\Tests\Lens\LensProbe'], [$node['kind'], $node['name'], $node['label'], $node['template'], $node['post'], $node['class']]);
        self::assertSame(['Blocks/LensProbe/LensProbe.php', 'Blocks/LensProbe/index.php', 'Blocks/LensProbe/style.css'], $node['files'], 'class file first, no dotfiles');

        $template = $map['files']['Blocks/LensProbe/index.php'];
        self::assertSame([['text' => 'Probe', 'line' => 1, 'kind' => 'html'], ['text' => 'Read more', 'line' => 2, 'kind' => 'i18n'], ['text' => 'taw', 'line' => 2, 'kind' => 'literal']], $template['texts']);
        self::assertSame(['1' => '<h2>Probe</h2>', '2' => "<p><?php echo esc_html__('Read more', 'taw'); ?></p>", '3' => ''], $template['lines']);
        self::assertSame(realpath($this->theme) . '/Blocks/LensProbe/index.php', $template['abs']);
        self::assertArrayNotHasKey('texts', $map['files']['Blocks/LensProbe/LensProbe.php'], 'class files hold labels, not output: not scanned');
        self::assertNull($map['nodes']['0']['template'], 'a missing template file is left out');
        self::assertSame('Home', $map['nodes']['0']['label']);
    }

    public function testPathsOutsideTheThemeStartAtWpContent(): void
    {
        $plugin = sys_get_temp_dir() . '/site-' . uniqid() . '/wp-content/mu-plugins';
        mkdir($plugin, 0777, true);
        file_put_contents($plugin . '/page.php', '<p>Hi</p>');
        SourceMap::enable();
        SourceMap::root($plugin . '/page.php');

        $map = SourceMap::manifest($this->theme);

        self::assertSame('wp-content/mu-plugins/page.php', $map['nodes']['0']['template']);
        self::assertSame('page.php', $map['nodes']['0']['name']);
        exec('rm -rf ' . escapeshellarg(dirname($plugin, 2)));
    }

    public function testTheScriptCannotBeClosedByAValue(): void
    {
        SourceMap::enable();
        $this->read('evil', '</script><script>alert(1)</script> & more');

        $script = SourceMap::script($this->theme);

        self::assertStringStartsWith('<script type="application/json" id="taw-lens-map">{"version":1,', $script);
        self::assertSame(1, substr_count($script, '</script>'));
        self::assertStringContainsString('</script>', $script);
        $json = json_decode(substr($script, strlen('<script type="application/json" id="taw-lens-map">'), -strlen('</script>')), true);
        self::assertSame('</script><script>alert(1)</script> & more', $json['nodes']['0']['reads'][0]['value']);
    }

    public function testHumanize(): void
    {
        self::assertSame('Site header', SourceMap::humanize('site-header'));
        self::assertSame('FAQ', SourceMap::humanize('FAQ'));
        self::assertSame('Hero banner', SourceMap::humanize('HeroBanner'));
        self::assertSame('Content page', SourceMap::humanize('content_page.php'));
        self::assertSame('Call to action CTA', SourceMap::humanize('CallToAction-CTA'));
    }

    private function read(string $key, string $value): void
    {
        SourceMap::record(['store' => 'post', 'object' => 1, 'key' => $key, 'type' => 'text', 'label' => ucfirst($key), 'value' => $value, 'admin' => '']);
    }
}
