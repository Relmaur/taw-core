<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use PHPUnit\Framework\TestCase;
use TAW\Core\Lens\TemplateText;

final class TemplateTextTest extends TestCase
{
    protected function setUp(): void
    {
        TemplateText::resetForTests();
    }

    public function testFindsHtmlTextWithItsLine(): void
    {
        $texts = TemplateText::fromCode("<section>\n  <h2>Why TAW Lens</h2>\n  <p>\n    Get started\n  </p>\n</section>\n");

        self::assertSame([
            ['text' => 'Why TAW Lens', 'line' => 2, 'kind' => 'html'],
            ['text' => 'Get started', 'line' => 4, 'kind' => 'html'],
        ], $texts);
    }

    public function testTellsTranslatableStringsFromLiterals(): void
    {
        $code = "<?php\n\$a = esc_html__( 'Read more', 'taw-theme' );\n\$b = 'Call us today';\n_e('Hello', 'taw');\n\\__('Back', 'taw');\n";

        self::assertSame([
            ['text' => 'Read more', 'line' => 2, 'kind' => 'i18n'],
            ['text' => 'taw-theme', 'line' => 2, 'kind' => 'literal'],
            ['text' => 'Call us today', 'line' => 3, 'kind' => 'literal'],
            ['text' => 'Hello', 'line' => 4, 'kind' => 'i18n'],
            ['text' => 'taw', 'line' => 4, 'kind' => 'literal'],
            ['text' => 'Back', 'line' => 5, 'kind' => 'i18n'],
            ['text' => 'taw', 'line' => 5, 'kind' => 'literal'],
        ], $texts = TemplateText::fromCode($code));
    }

    public function testTextAfterAClosingPhpTagCounts(): void
    {
        $texts = TemplateText::fromCode("<p>Last edited on <?php echo \$date; ?> by the team.</p>");

        self::assertSame(['Last edited on', 'by the team.'], array_column($texts, 'text'));
    }

    public function testTheRestOfATagIsNotText(): void
    {
        $texts = TemplateText::fromCode('<a href="<?php echo $url; ?>" class="btn">Go</a>');

        self::assertSame(['Go'], array_column($texts, 'text'));
    }

    public function testSkipsWhatCannotBeVisibleText(): void
    {
        $texts = TemplateText::fromCode("<?php \$x = ['1', '', ' - ', \"\\n\"]; ?>\n<p> &nbsp; </p><p>&amp;</p><p>&copy; 2026 Acme</p>");

        self::assertSame(['© 2026 Acme'], array_column($texts, 'text'));
    }

    public function testDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $texts = TemplateText::fromCode("<p>Rock &amp;\n   roll</p><?php echo 'It\\'s   here'; ?>");

        self::assertSame(['Rock & roll', "It's here"], array_column($texts, 'text'));
    }

    public function testScansAFileOnceAndGivesLinesAroundEachText(): void
    {
        $file = sys_get_temp_dir() . '/taw-lens-text-' . uniqid() . '.php';
        file_put_contents($file, "<div>\n  <a class=\"btn\">\n    See our work\n  </a>\n</div>\n");

        $texts = TemplateText::scan($file);
        self::assertSame([['text' => 'See our work', 'line' => 3, 'kind' => 'html']], $texts);
        self::assertSame(['2' => '  <a class="btn">', '3' => '    See our work', '4' => '  </a>'], TemplateText::lines($file, [3]));

        unlink($file);
        self::assertSame($texts, TemplateText::scan($file), 'cached for the request');
    }

    public function testAMissingFileHasNoText(): void
    {
        self::assertSame([], TemplateText::scan('/nope/missing.php'));
        self::assertSame([], TemplateText::lines('/nope/missing.php', [1]));
    }
}
