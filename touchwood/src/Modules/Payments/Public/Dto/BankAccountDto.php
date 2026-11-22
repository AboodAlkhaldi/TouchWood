<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

/**
 * The account a store's companies transfer to (payments.md §1.9, b2b.md amendments 12(b), 13(c)). There is one
 * only while bank transfer is on — all three settings filled in.
 */
final readonly class BankAccountDto
{
    public function __construct(
        /** As the store typed it, spaces included. */
        public string $iban,
        public string $bank,
        public string $holder,
    ) {}
}
