<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Media;

use Brain\Monkey\Functions;
use TAW\Core\Media\MediaFolders;
use TAW\Tests\TestCase;

/**
 * Media Folders is built entirely on a single hierarchical taxonomy
 * (taw_media_folder) registered on 'attachment' with show_in_rest => true
 * — that flag is what gives folders.js its wp/v2/taw_media_folder and
 * wp/v2/media?taw_media_folder= REST support for free, with no custom
 * REST endpoint class. This test locks in those args.
 */
final class MediaFoldersTaxonomyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    public function test_registers_a_hierarchical_taxonomy_on_attachments_with_rest_support(): void
    {
        Functions\expect('register_taxonomy')
            ->once()
            ->with(
                MediaFolders::TAXONOMY,
                'attachment',
                \Mockery::on(function (array $args): bool {
                    $this->assertTrue($args['hierarchical']);
                    $this->assertTrue($args['show_in_rest']);
                    $this->assertSame('upload_files', $args['capabilities']['assign_terms']);
                    $this->assertSame('upload_files', $args['capabilities']['manage_terms']);

                    return true;
                })
            );

        MediaFolders::registerTaxonomy();
    }
}
