<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One extra item a rejection asked this company for (b2b.md §1.2, amendment 4), in its staff-written
 * words.
 */
final readonly class RequestView
{
    public function __construct(
        public string $id,
        /** TEXT or FILE. */
        public string $kind,
        public string $label,
    ) {}
}
