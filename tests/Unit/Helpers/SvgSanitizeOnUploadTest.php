<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use TAW\Helpers\Svg;
use TAW\Tests\TestCase;

/**
 * Covers Svg::sanitizeOnUpload()'s fail-closed behavior — a real security
 * fix, not just a correctness one. wp_handle_upload() has already moved the
 * uploaded file into its final, publicly-servable uploads location by the
 * time this filter runs, so any failure to positively confirm the file is
 * safe must delete it outright rather than leave the original (potentially
 * script-bearing) file in place.
 *
 * Two distinct failure modes matter here, both converging on the same
 * outcome: enshrined/svg-sanitize returns false for malformed/unparseable
 * XML, but *throws* a LogicException for well-formed XML with no <svg> root
 * ("Got 0 svg elements, expected exactly one") — an uncaught exception here
 * would previously have fatally crashed the upload request while still
 * leaving the unsanitized file on disk.
 */
final class SvgSanitizeOnUploadTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);

        $this->tmpFile = (string) tempnam(sys_get_temp_dir(), 'taw-svg-test-');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        parent::tearDown();
    }

    public function test_valid_svg_is_sanitized_and_kept(): void
    {
        file_put_contents(
            $this->tmpFile,
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" onclick="alert(1)"/></svg>'
        );

        $result = Svg::sanitizeOnUpload(['file' => $this->tmpFile, 'type' => 'image/svg+xml']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFileExists($this->tmpFile);
        $this->assertStringNotContainsString('onclick', file_get_contents($this->tmpFile));
    }

    public function test_malformed_xml_is_rejected_and_file_deleted(): void
    {
        // Deliberately unparseable — an unclosed tag.
        file_put_contents($this->tmpFile, '<svg><rect');

        $result = Svg::sanitizeOnUpload(['file' => $this->tmpFile, 'type' => 'image/svg+xml']);

        $this->assertArrayHasKey('error', $result);
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function test_non_svg_root_element_is_rejected_and_file_deleted(): void
    {
        // Well-formed XML with an <html> root — no <svg> element at all.
        // enshrined/svg-sanitize throws a LogicException for this rather
        // than returning false; must still converge to a clean rejection.
        file_put_contents($this->tmpFile, '<html><body onload="alert(1)">hi<script>alert(2)</script></body></html>');

        $result = Svg::sanitizeOnUpload(['file' => $this->tmpFile, 'type' => 'image/svg+xml']);

        $this->assertArrayHasKey('error', $result);
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function test_non_svg_upload_type_is_left_untouched(): void
    {
        file_put_contents($this->tmpFile, 'not an svg at all');

        $result = Svg::sanitizeOnUpload(['file' => $this->tmpFile, 'type' => 'image/png']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFileExists($this->tmpFile);
        $this->assertSame('not an svg at all', file_get_contents($this->tmpFile));
    }
}
