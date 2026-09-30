<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class, like Parser.

/** A formula that doesn't parse: the message is the error code, $at its byte offset. */
final class FormulaError extends \RuntimeException
{
    public function __construct(string $code, public readonly int $at)
    {
        parent::__construct($code);
    }
}
