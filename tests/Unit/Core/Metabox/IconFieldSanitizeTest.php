<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * The 'icon' field type stores a bare Lucide icon name (see Lucide::render()),
 * so sanitization is sanitize_key() — same rule WordPress core uses for any
 * lowercase-kebab identifier. This mirrors sanitize_key()'s real behavior
 * (lowercase, then strip everything but a-z0-9_-) closely enough to catch a
 * regression without needing a real WordPress install.
 */
final class IconFieldSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_key')->alias(
            fn(mixed $key): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''
        );
    }

    public function test_valid_kebab_case_icon_name_survives_unchanged(): void
    {
        $this->assertSame(
            'arrow-right',
            Metabox::sanitizeValue(['type' => 'icon'], 'arrow-right')
        );
    }

    public function test_disallowed_characters_are_stripped_and_lowercased(): void
    {
        $this->assertSame(
            'houseicon',
            Metabox::sanitizeValue(['type' => 'icon'], 'House Icon!')
        );
    }

    public function test_empty_value_stays_empty(): void
    {
        $this->assertSame('', Metabox::sanitizeValue(['type' => 'icon'], ''));
    }
}
