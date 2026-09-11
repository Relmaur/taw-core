<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Vector;

use TAW\Core\Rag\Vector\VectorMath;
use TAW\Tests\TestCase;

final class VectorMathTest extends TestCase
{
    public function test_identical_vectors_have_similarity_one(): void
    {
        $this->assertEqualsWithDelta(1.0, VectorMath::cosineSimilarity([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 0.0001);
    }

    public function test_orthogonal_vectors_have_similarity_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, VectorMath::cosineSimilarity([1.0, 0.0], [0.0, 1.0]), 0.0001);
    }

    public function test_opposite_vectors_have_similarity_negative_one(): void
    {
        $this->assertEqualsWithDelta(-1.0, VectorMath::cosineSimilarity([1.0, 0.0], [-1.0, 0.0]), 0.0001);
    }

    public function test_mismatched_lengths_return_zero(): void
    {
        $this->assertSame(0.0, VectorMath::cosineSimilarity([1.0, 2.0], [1.0]));
    }

    public function test_empty_vectors_return_zero(): void
    {
        $this->assertSame(0.0, VectorMath::cosineSimilarity([], []));
    }

    public function test_zero_vector_returns_zero(): void
    {
        $this->assertSame(0.0, VectorMath::cosineSimilarity([0.0, 0.0], [1.0, 1.0]));
    }

    public function test_pack_and_unpack_round_trips(): void
    {
        $vector = [0.1, -0.5, 3.25, 0.0, 100.75];

        $unpacked = VectorMath::unpackVector(VectorMath::packVector($vector));

        $this->assertCount(count($vector), $unpacked);
        foreach ($vector as $i => $value) {
            $this->assertEqualsWithDelta($value, $unpacked[$i], 0.0001);
        }
    }
}
