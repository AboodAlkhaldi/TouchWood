<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelCustomerDeletion;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\StaffCustomerAction;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

/**
 * Support stops a deletion the customer asked them to stop (amendment 43): the same admin-only
 * action as starting one. Signing in stops it too — this is for a customer who cannot, which is
 * exactly the person who would otherwise lose the account.
 */
final readonly class CancelCustomerDeletionHandler
{
    public const string PERMISSION = AccessPermissions::CUSTOMER_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCustomerAction $action,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws CustomerNotFound|InvalidAccessAttribute|InvalidCustomerStatus
     */
    public function handle(CancelCustomerDeletion $command): void
    {
        [$store, $reason] = $this->action->about($command->customerId, $command->reason);
        $this->authorizer->authorize(self::PERMISSION, $store);

        $this->db->transaction(function () use ($command, $reason): void {
            $customer = $this->customers->byId($command->customerId) ?? throw new CustomerNotFound($command->customerId);
            $customer->cancelDeletion();

            if ($customer->pullChanges() === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::deletion('access.customer.deletion_cancelled', $customer, $reason));
            $this->events->dispatch(new CustomerDeletionCancelled((string) Str::uuid(), $customer->id(), CarbonImmutable::now()));
        }, 3);
    }
}
