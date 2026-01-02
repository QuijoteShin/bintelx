<?php
# bintelx/kernel/TestAssertions.php
# Test assertion helpers (strict, no false positives)

namespace bX;

class TestAssertions
{
    public static function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \Exception("ASSERTION FAILED: $message");
        }
    }

    public static function assertFalse(bool $condition, string $message): void
    {
        if ($condition) {
            throw new \Exception("ASSERTION FAILED: $message");
        }
    }

    public static function assertEquals($expected, $actual, string $message): void
    {
        $isEqual = (is_numeric($expected) && is_numeric($actual))
            ? ($expected == $actual)
            : ($expected === $actual);

        if (!$isEqual) {
            $expectedStr = is_scalar($expected) ? $expected : json_encode($expected);
            $actualStr = is_scalar($actual) ? $actual : json_encode($actual);
            throw new \Exception("ASSERTION FAILED: $message (expected=$expectedStr, got=$actualStr)");
        }
    }

    public static function assertNotEquals($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            throw new \Exception("ASSERTION FAILED: $message (both values are equal: $expected)");
        }
    }

    public static function assertArrayHasKey(string $key, array $array, string $message): void
    {
        if (!array_key_exists($key, $array)) {
            throw new \Exception("ASSERTION FAILED: $message (key '$key' not found)");
        }
    }

    public static function assertCount(int $expected, array $array, string $message): void
    {
        $actual = count($array);
        if ($actual !== $expected) {
            throw new \Exception("ASSERTION FAILED: $message (expected count=$expected, got=$actual)");
        }
    }

    public static function assertBcEquals(string $expected, string $actual, int $precision, string $message): void
    {
        if (bccomp($expected, $actual, $precision) !== 0) {
            throw new \Exception("ASSERTION FAILED: $message (expected=$expected, got=$actual)");
        }
    }

    public static function assertGreaterThan($expected, $actual, string $message): void
    {
        if ($actual <= $expected) {
            throw new \Exception("ASSERTION FAILED: $message (expected >$expected, got=$actual)");
        }
    }

    public static function assertNull($value, string $message): void
    {
        if ($value !== null) {
            $valueStr = is_scalar($value) ? $value : json_encode($value);
            throw new \Exception("ASSERTION FAILED: $message (expected NULL, got=$valueStr)");
        }
    }

    public static function assertNotNull($value, string $message): void
    {
        if ($value === null) {
            throw new \Exception("ASSERTION FAILED: $message (value is NULL)");
        }
    }
}
