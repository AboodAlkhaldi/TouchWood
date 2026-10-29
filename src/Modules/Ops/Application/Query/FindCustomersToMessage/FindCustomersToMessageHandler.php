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
use Shared\Domain\ValueObject\StoreId;

/**
 * Finds the customer a message goes to (ops.md §1.8: "found by name, email or phone"), under
 * `ops.message.send_one` in the store, through Access's own search (AccessApi::customerIdsMatching):
 * a name in any store, an email or a phone only among the customers the reader may view. Anonymized
 * accounts are never offered. At most 20.
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
        $viewable = $this->access->customerViewStores();
        $viewableIds = $viewable === null ? null : array_map(static fn (StoreId $id): string => $id->value, $viewable);
        $found = [];

        foreach ($customers as $customer) {
            if ($customer->anonymized) {
                continue;
            }

            $found[] = new CustomerToMessage(
                strtolower($customer->id),
                $customer->firstName,
                $customer->lastName,
                $viewableIds === null || in_array($customer->homeStoreId, $viewableIds, true) ? $customer->email : null,
            );
        }

        return $found;
    }
}
