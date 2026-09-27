<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * Everything an application sends about the company, complete — what a draft becomes when it is
 * sent, and what the company then holds (b2b.md §1.2: the company row carries the latest values
 * sent; each application keeps its own copy).
 */
final readonly class CompanyDetails
{
    public function __construct(
        public CompanyName $name,
        public CompanyTypeChoice $type,
        public RegistrationNumber $crNumber,
        public RegistrationNumber $taxNumber,
        public CompanyAddress $address,
    ) {}
}
