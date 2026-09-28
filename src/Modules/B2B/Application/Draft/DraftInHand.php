<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Draft;

use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;

/**
 * The account's open draft, read under its locks for a change, with the company behind it — none
 * before the first application is sent (b2b.md §1.1).
 */
final readonly class DraftInHand
{
    public function __construct(
        public Application $draft,
        public ?Company $company,
    ) {}
}
