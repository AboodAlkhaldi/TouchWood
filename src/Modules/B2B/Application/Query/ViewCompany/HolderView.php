<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewCompany;

/**
 * The account holder — the company's responsible person (b2b.md §1.1), read from Access.
 */
final readonly class HolderView
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        /** The account was emptied: the name and email are placeholders (§1.1). */
        public bool $anonymized,
    ) {}
}
