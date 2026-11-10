<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Mail;

use TAW\Core\Mail\MailTemplate;
use TAW\Tests\TestCase;

/**
 * MailTemplate::fill(): optional blocks and literal placeholder values.
 */
final class MailTemplateTest extends TestCase
{
    public function test_an_optional_block_goes_when_its_variable_is_empty_or_unset(): void
    {
        $html = '<p>A</p><!--if:date--><p>{{date}}</p><!--/if:date--><!--if:note--><i>{{note}}</i><!--/if:note--><p>B</p>';

        $this->assertSame('<p>A</p><p>B</p>', MailTemplate::fill($html, ['date' => '', 'x' => 'y']));
    }

    public function test_an_optional_block_stays_with_its_markers_when_its_variable_has_a_value(): void
    {
        $html = "<!--if:date-->\n<p>{{date}}</p>\n<!--/if:date-->";

        $this->assertSame("<!--if:date-->\n<p>5 dic</p>\n<!--/if:date-->", MailTemplate::fill($html, ['date' => '5 dic']));
        $this->assertSame("<!--if:date-->\n<p>0</p>\n<!--/if:date-->", MailTemplate::fill($html, ['date' => '0']), '"0" is a value');
    }

    public function test_differently_named_blocks_nest(): void
    {
        $html = '<!--if:a-->[a<!--if:b-->[b]<!--/if:b-->]<!--/if:a-->';

        $this->assertSame('<!--if:a-->[a]<!--/if:a-->', MailTemplate::fill($html, ['a' => '1', 'b' => '']));
        $this->assertSame('', MailTemplate::fill($html, ['a' => '', 'b' => '1']));
    }

    public function test_values_are_inserted_literally(): void
    {
        $this->assertSame('<p>$100 \\0 $1</p>', MailTemplate::fill('<p>{{ price }}</p>', ['price' => '$100 \\0 $1']));
        $this->assertSame('<p>7 7</p>', MailTemplate::fill('<p>{{n}} {{n}}</p>', ['n' => 7]));
        $this->assertSame('<p>{{other}}</p>', MailTemplate::fill('<p>{{other}}</p>', ['n' => 7]), 'unknown placeholders stay');
    }

    public function test_a_repeat_block_is_written_once_per_row_without_its_markers(): void
    {
        $html = '<h1>{{title}}</h1><!--repeat:row--><li>{{name}}<!--if:note--> ({{note}})<!--/if:note--> · {{title}}</li><!--/repeat:row--><p>end</p>';

        $this->assertSame(
            '<h1>Hoy</h1><li>Ana (vegetariana) · Hoy</li><li>Luis · Hoy</li><p>end</p>',
            MailTemplate::fill($html, ['title' => 'Hoy', 'row' => [['name' => 'Ana', 'note' => 'vegetariana'], ['name' => 'Luis', 'note' => '']]])
        );
    }

    public function test_an_empty_or_missing_list_removes_the_repeat_block(): void
    {
        $html = '<p>a</p><!--repeat:row--><li>{{name}}</li><!--/repeat:row--><p>b</p>';

        $this->assertSame('<p>a</p><p>b</p>', MailTemplate::fill($html, ['row' => []]));
        $this->assertSame('<p>a</p><p>b</p>', MailTemplate::fill($html, []));
        $this->assertSame('<p>a</p><p>b</p>', MailTemplate::fill($html, ['row' => 'not a list']));
    }

    public function test_row_values_are_literal_and_win_over_the_others(): void
    {
        $html = '<!--repeat:row-->[{{name}} {{city}}]<!--/repeat:row-->';

        $this->assertSame('[$1 Zac][Ana León]', MailTemplate::fill($html, ['city' => 'Zac', 'row' => [['name' => '$1'], ['name' => 'Ana', 'city' => 'León']]]));
    }
}
