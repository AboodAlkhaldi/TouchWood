<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Listener;

use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\B2B\Application\Account\CompanyAnonymizer;

/**
 * Access anonymized an account (access.md §1.10): B2B empties the company behind it, its sent
 * applications and their papers, and deletes an unsent draft (b2b.md §6, amendment 12(a)). Runs once
 * Access's change has committed; an individual account has nothing here.
 */
final readonly class AnonymizeCompany
{
    public function __construct(
        private CompanyAnonymizer $anonymizer,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->anonymizer->anonymize($event->customerId);
    }
}
