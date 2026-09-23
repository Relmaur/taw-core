<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use PHPUnit\Framework\TestCase;
use TAW\Core\Schema\Validator;

/**
 * resources/schema/taw-schema-1.0.json is the published copy of the
 * Validator's rules (for editor autocomplete). The Validator is the
 * authority; these tests stop the two from drifting apart (ADR-0004 § 4).
 */
final class JsonSchemaFileTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 4) . '/resources/schema/taw-schema-1.0.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_field_type_enum_matches_the_validator(): void
    {
        $this->assertSame(Validator::FIELD_TYPES, $this->schema['$defs']['field']['properties']['type']['enum']);
    }

    public function test_kind_enum_matches_the_validator(): void
    {
        $this->assertSame(Validator::KINDS, $this->schema['properties']['kind']['enum']);
    }

    public function test_version_matches_the_validator(): void
    {
        $this->assertSame(Validator::VERSION, $this->schema['properties']['version']['const']);
    }

    public function test_each_kind_has_a_branch_listing_the_same_keys_as_the_validator(): void
    {
        $allowed = (new \ReflectionClassConstant(Validator::class, 'ALLOWED_KEYS'))->getValue();
        $common = ['$schema', 'version', 'kind', 'key', 'override'];

        foreach ($this->schema['allOf'] as $branch) {
            $kind = $branch['if']['properties']['kind']['const'];
            $keys = array_keys($branch['then']['properties']);

            $this->assertEqualsCanonicalizing([...$common, ...$allowed[$kind]], $keys, "branch for {$kind}");
        }
    }
}
