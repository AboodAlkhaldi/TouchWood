<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\B2B\Application\Account\CompanyAnonymizer;

/**
 * Access anonymized an account (access.md §1.10): B2B empties the company behind it, its sent
 * applications and their papers, and deletes an unsent draft (b2b.md §6, amendments 12(a), 13(a)).
 * An individual account has nothing here.
 *
 * **From the queue** (13(a)): Access's sweep only queues this, so a failure here never reaches it —
 * its log stays true and every other listener still runs. A failed attempt is retried, the last one
 * waits in `failed_jobs`. That holds on an asynchronous connection — the database queue the
 * application runs on (handoff §3); on `sync`, as the tests run, the job runs at once and a failure
 * does reach the sweep. **Done once** however many times it runs: the account's lock lets one run
 * at a time, and a run that finds the placeholders in place changes and records nothing.
 */
final class AnonymizeCompany implements ShouldQueue
{
    /** Five attempts over about 36 minutes: a lock that waits too long, or the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CompanyAnonymizer $anonymizer,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->anonymizer->anonymize($event->customerId);
    }
}
