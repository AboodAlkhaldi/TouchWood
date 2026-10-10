<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CorrectVariantCode;

/**
 * A mistyped code, corrected (catalog.md §1.2): the variant's own — it alone changes (amendment 16(a)).
 */
final readonly class CorrectVariantCode
{
    public function __construct(
        public string $variantId,
        public string $code,
    ) {}
}
