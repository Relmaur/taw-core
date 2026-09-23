<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Core\Schema\CollisionReport;
use TAW\Core\Schema\Registry;
use TAW\Tests\TestCase;

/**
 * Shared setup for the Schema tests: every piece of request-wide static
 * state the schema touches (its own registry and collision list, plus
 * Metabox's and OptionsPage's field registries) starts empty, and
 * _doing_it_wrong() notices are captured so tests can assert on them.
 */
abstract class SchemaTestCase extends TestCase
{
    /** @var list<string> messages passed to _doing_it_wrong() */
    protected array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();

        Registry::resetForTests();
        CollisionReport::resetForTests();
        $this->resetStatic(Metabox::class, 'fieldRegistry', []);
        $this->resetStatic(OptionsPage::class, 'fieldRegistry', []);

        $this->notices = [];
        Functions\when('_doing_it_wrong')->alias(function (string $function, string $message): void {
            $this->notices[] = $message;
        });
    }

    protected function tearDown(): void
    {
        // Brain Monkey expectations (expect()->once(), ->never()…) are real
        // assertions verified by Mockery at teardown; count them so PHPUnit
        // doesn't flag expectation-only tests as assertion-free.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());

        Registry::resetForTests();
        CollisionReport::resetForTests();
        $this->resetStatic(Metabox::class, 'fieldRegistry', []);
        $this->resetStatic(OptionsPage::class, 'fieldRegistry', []);

        parent::tearDown();
    }

    protected function resetStatic(string $class, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }
}
