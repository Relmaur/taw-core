<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Assets;

use TAW\Hub\Assets\PayloadException;
use TAW\Hub\Assets\ViteManifestValidator;
use TAW\Tests\TestCase;

final class ViteManifestValidatorTest extends TestCase
{
    private ViteManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ViteManifestValidator();
    }

    public function test_a_coherent_manifest_passes(): void
    {
        $manifest = [
            'resources/js/app.js' => [
                'file' => 'assets/app.a1.js',
                'css'  => ['assets/app.b2.css'],
            ],
        ];
        $files = ['assets/app.a1.js', 'assets/app.b2.css', 'manifest.json'];

        $this->validator->validate($manifest, $files);
        $this->addToAssertionCount(1);
    }

    public function test_an_empty_manifest_is_rejected(): void
    {
        $this->expectExceptionReason(PayloadException::MANIFEST_INVALID, fn () => $this->validator->validate([], []));
    }

    public function test_a_referenced_file_not_in_the_payload_is_rejected(): void
    {
        $this->expectExceptionReason(
            PayloadException::MANIFEST_INVALID,
            fn () => $this->validator->validate(
                ['x' => ['file' => 'assets/missing.js']],
                ['assets/present.js'],
            ),
        );
    }

    public function test_a_referenced_css_asset_not_in_the_payload_is_rejected(): void
    {
        $this->expectExceptionReason(
            PayloadException::MANIFEST_INVALID,
            fn () => $this->validator->validate(
                ['x' => ['file' => 'assets/app.js', 'css' => ['assets/gone.css']]],
                ['assets/app.js'],
            ),
        );
    }

    public function test_a_chunk_without_a_file_key_is_rejected(): void
    {
        $this->expectExceptionReason(
            PayloadException::MANIFEST_INVALID,
            fn () => $this->validator->validate(['x' => ['css' => []]], []),
        );
    }

    private function expectExceptionReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected PayloadException: {$reason}");
        } catch (PayloadException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }
}
