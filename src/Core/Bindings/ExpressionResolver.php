<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves one binding to the value a block attribute gets (ADR-0010
 * decision 9). FieldResolver is the first; dynamic tags (roadmap Phase 7)
 * add others without changing the `taw/field` source.
 */
interface ExpressionResolver
{
    /**
     * @return mixed The attribute's value; null keeps the block's saved content, false removes an HTML attribute.
     */
    public function resolve(Reference $ref, BindingContext $context, Target $target): mixed;
}
