<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\FindCustomersToMessage;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * Finds the customer a message goes to (ops.md §1.8: "found by name, email or phone"), under
 * `ops.message.send_one` in the store, through Access's own search (AccessApi::customerIdsMatching),
 * and offers only those a message may reach (Writers::mayWriteToOne; owner, 2026-10-30): registered in
 * a store where the reader holds send_one and may view customers - so their email is always shown.
 * Anonymized accounts are never offered. At most 20.
 */
final readonly class FindCustomersToMessageHandler
{
    public const int MOST = 20;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private AccessApi $access,
    ) {}

    /**
     * @return list<CustomerToMessage>
     *
     * @throws DomainError|InvalidOpsInput|Unauthorized
     */
    public function handle(FindCustomersToMessage $query): array
    {
        $store = $this->writers->store($query->storeId);
        $this->authorizer->authorize(OpsPermissions::MESSAGE_SEND_ONE, PermissionScope::store($store->storeId()));

        $customers = $this->access->customers($this->access->customerIdsMatching($query->text, self::MOST));
        $found = [];

        foreach ($customers as $customer) {
            if (! $this->writers->mayWriteToOne($customer)) {
                continue;
            }

            $found[] = new CustomerToMessage(strtolower($customer->id), $customer->firstName, $customer->lastName, $customer->email);
        }

        return $found;
    }
}
