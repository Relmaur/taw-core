<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * Regression test for a bug where sanitizeRepeaterRows() encoded non-ASCII
 * characters (accents, ñ, etc.) as \uXXXX JSON escapes. update_post_meta()
 * internally runs wp_unslash() -> stripslashes() on every value it saves,
 * and stripslashes() eats the backslash before ANY character, not just
 * quotes — so "Ñ" in the JSON string became literal "u00d1" in the
 * stored postmeta. JSON_UNESCAPED_UNICODE avoids emitting those escapes in
 * the first place, so there's no backslash for wp_unslash() to corrupt.
 *
 * This suite fakes get_post_meta/update_post_meta with a simple in-memory
 * store and replicates WordPress's real wp_unslash()/sanitize_text_field()
 * behavior closely enough to reproduce the actual corruption path end to
 * end, without needing a real WordPress install.
 */
final class RepeaterUnicodeTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->postMeta = [];

        Functions\when('wp_json_encode')->alias(
            fn(mixed $data, int $flags = 0): string|false => json_encode($data, $flags)
        );

        // Real WordPress: wp_unslash() === stripslashes_deep(), which for a
        // plain string is just stripslashes().
        Functions\when('wp_unslash')->alias(
            fn(mixed $value): mixed => is_string($value) ? stripslashes($value) : $value
        );

        Functions\when('sanitize_text_field')->alias(
            fn(mixed $value): string => trim(strip_tags((string) $value))
        );

        // update_post_meta() runs the value through wp_unslash() before
        // storing it — that's the actual root cause of the corruption.
        Functions\when('update_post_meta')->alias(function (int $postId, string $key, mixed $value): bool {
            $this->postMeta[$postId][$key] = wp_unslash($value);
            return true;
        });

        Functions\when('get_post_meta')->alias(
            fn(int $postId, string $key, bool $single) => $this->postMeta[$postId][$key] ?? ''
        );
    }

    public function test_accented_characters_survive_the_full_save_and_read_round_trip(): void
    {
        $fieldConfig = [
            'fields' => [
                ['id' => 'label', 'type' => 'text'],
            ],
        ];

        $json = Metabox::sanitizeRepeaterRows($fieldConfig, [
            ['label' => 'Diseño de AÑOS - café résumé'],
        ]);

        update_post_meta(1, '_taw_items', $json);

        $rows = Metabox::get_repeater(1, 'items');

        $this->assertSame('Diseño de AÑOS - café résumé', $rows[0]['label'] ?? null);
    }
}
