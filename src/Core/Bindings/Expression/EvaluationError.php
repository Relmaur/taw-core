<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class, like Parser.

/**
 * An expression that parsed but can't be evaluated (division by zero, text
 * where a number is needed…). The message is the error code; the token that
 * raised it renders empty.
 */
final class EvaluationError extends \RuntimeException
{
}
