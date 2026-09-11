<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Vector;

/**
 * No ABSPATH guard — pure math, no WordPress dependency.
 *
 * pack()/unpack() use float32 LE ('g'), the same blob layout sqlite-vec's
 * `vec0` virtual tables expect for a `float[N]` column — rows written today
 * (pure-PHP path) need no migration the day the extension becomes available
 * on a target host.
 */
final class VectorMath
{
    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param list<float> $vector
     */
    public static function packVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpackVector(string $blob): array
    {
        $unpacked = unpack('g*', $blob);
        if ($unpacked === false) {
            return [];
        }

        return array_values($unpacked);
    }
}
