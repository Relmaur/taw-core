<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Vector;

use PDO;
use TAW\Core\Rag\Vector\VectorCapability;
use TAW\Tests\TestCase;

final class VectorCapabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        VectorCapability::setOverrideForTests(null);
        parent::tearDown();
    }

    public function test_real_detection_is_false_when_the_extension_is_not_installed(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->assertFalse(VectorCapability::sqliteVecAvailable($pdo));
    }

    public function test_override_forces_true(): void
    {
        VectorCapability::setOverrideForTests(true);
        $pdo = new PDO('sqlite::memory:');

        $this->assertTrue(VectorCapability::sqliteVecAvailable($pdo));
    }

    public function test_override_forces_false(): void
    {
        VectorCapability::setOverrideForTests(false);
        $pdo = new PDO('sqlite::memory:');

        $this->assertFalse(VectorCapability::sqliteVecAvailable($pdo));
    }

    public function test_null_override_restores_real_detection(): void
    {
        VectorCapability::setOverrideForTests(true);
        VectorCapability::setOverrideForTests(null);
        $pdo = new PDO('sqlite::memory:');

        $this->assertFalse(VectorCapability::sqliteVecAvailable($pdo));
    }
}
