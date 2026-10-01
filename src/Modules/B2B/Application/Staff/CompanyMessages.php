<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Staff;

use Closure;
use Illuminate\Database\Connection;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;

/**
 * The customer's email about a staff decision (b2b.md §2.3, amendment 1): approved — with the
 * staff member's note when there is one —, rejected with its reason, suspended with its reason;
 * reinstating sends none. Sent through Access's messages, **only once the decision has committed**:
 * a decision that rolls back never tells anybody anything, and a retried transaction sends once.
 *
 * An account Access no longer has, or one anonymized, is written to by nobody.
 */
final readonly class CompanyMessages
{
    public function __construct(
        private AccessApi $access,
        private SecurityMessages $messages,
        private Connection $db,
    ) {}

    /**
     * @param  Closure(SecurityMessages, CustomerDto): void  $send
     */
    public function afterCommit(string $customerId, Closure $send): void
    {
        $this->db->afterCommit(function () use ($customerId, $send): void {
            $customer = $this->access->customer($customerId);

            if ($customer === null || $customer->anonymized) {
                return;
            }

            $send($this->messages, $customer);
        });
    }
}
