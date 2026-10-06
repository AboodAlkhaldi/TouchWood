<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CorrectVariantCode;

/**
 * A mistyped code, corrected (catalog.md §1.2): the variant names the code to change; every variant
 * of its product carrying it takes the new one (amendment 3(e)).
 */
final readonly class CorrectVariantCode
{
    public function __construct(
        public string $variantId,
        public string $code,
    ) {}
}
