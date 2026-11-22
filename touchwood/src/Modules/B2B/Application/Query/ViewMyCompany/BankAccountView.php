<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * The account an approved company transfers to: its home store's (b2b.md §2.3, amendment 12(b)).
 * The IBAN is as the store typed it.
 */
final readonly class BankAccountView
{
    public function __construct(
        public string $iban,
        public string $bank,
        public string $holder,
    ) {}
}
