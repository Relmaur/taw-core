<?php

declare(strict_types=1);

namespace TAW\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for taw-core's unit suite — wires up Brain Monkey so
 * every test can stub individual WP functions (add_action, __(),
 * sanitize_text_field(), etc.) instead of needing a real WordPress
 * install. Every test class in tests/Unit/ should extend this.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reach into a private/protected method for direct testing. Several
     * classes in this codebase (Form especially) keep validation logic
     * private by design — it's an internal detail of a public API, not
     * something a caller should reach into directly. Reflection lets tests
     * verify that internal logic without widening the class's real API
     * surface just to make it testable.
     *
     * @param object $object
     * @param mixed  ...$args
     * @return mixed
     */
    protected function callMethod(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
