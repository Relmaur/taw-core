<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Media;

use TAW\Core\Media\MediaFolders;
use TAW\Tests\TestCase;

/**
 * Covers the Grid-view sidebar's server-side half: filterAjaxQueryAttachmentsArgs()
 * (the ajax_query_attachments_args filter that lets the sidebar live-filter
 * wp.media's Backbone Grid view) and the buildTaxQueryForFolder() helper it
 * shares with the classic List view's applyFolderFilterQuery(). Both are pure
 * array-in/array-out with no WordPress function calls, so no Brain Monkey
 * stubs are needed here.
 */
final class MediaFoldersAjaxQueryTest extends TestCase
{
    public function test_returns_query_unchanged_when_no_folder_param_present(): void
    {
        $result = MediaFolders::filterAjaxQueryAttachmentsArgs(['post_type' => 'attachment']);

        $this->assertSame(['post_type' => 'attachment'], $result);
    }

    public function test_adds_tax_query_by_term_id_for_numeric_folder_value(): void
    {
        $result = MediaFolders::filterAjaxQueryAttachmentsArgs([MediaFolders::TAXONOMY => '42']);

        $this->assertSame([[
            'taxonomy' => MediaFolders::TAXONOMY,
            'field'    => 'term_id',
            'terms'    => 42,
        ]], $result['tax_query']);
    }

    public function test_adds_not_exists_tax_query_for_unfiled(): void
    {
        $result = MediaFolders::filterAjaxQueryAttachmentsArgs([MediaFolders::TAXONOMY => 'unfiled']);

        $this->assertSame('NOT EXISTS', $result['tax_query'][0]['operator']);
    }

    public function test_ignores_empty_string_folder_value(): void
    {
        $result = MediaFolders::filterAjaxQueryAttachmentsArgs([MediaFolders::TAXONOMY => '']);

        $this->assertArrayNotHasKey('tax_query', $result);
    }

    public function test_build_tax_query_for_folder_handles_slug(): void
    {
        $result = $this->callMethod(new MediaFolders(), 'buildTaxQueryForFolder', 'blog');

        $this->assertSame('slug', $result[0]['field']);
        $this->assertSame('blog', $result[0]['terms']);
    }

    public function test_build_tax_query_for_folder_returns_null_for_empty(): void
    {
        $this->assertNull($this->callMethod(new MediaFolders(), 'buildTaxQueryForFolder', ''));
    }
}
