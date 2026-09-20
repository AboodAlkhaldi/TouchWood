<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteCustomerOnRequest;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletionHandler;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Customer\StaffCustomerAction;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

use function Illuminate\Support\defer;

/**
 * Support deletes an account the customer asked them to delete (spec §1.10, §3.3): an admin-only
 * action in the customer's home store, and the reason is what the customer said (amendment 43).
 *
 * It behaves exactly like the customer asking themselves: the same fourteen days, the same email
 * telling them the date and that signing in cancels it. Somebody who did not ask therefore learns
 * of it and can stop it.
 */
final readonly class DeleteCustomerOnRequestHandler
{
    public const string PERMISSION = AccessPermissions::CUSTOMER_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCustomerAction $action,
        private CustomerRepository $customers,
        private CustomerMapper $mapper,
        private SecurityMessages $messages,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws CustomerNotFound|InvalidAccessAttribute|InvalidCustomerStatus
     */
    public function handle(DeleteCustomerOnRequest $command): void
    {
        [$store, $reason] = $this->action->about($command->customerId, $command->reason);
        $this->authorizer->authorize(self::PERMISSION, $store);
        $on = CarbonImmutable::now()->addDays(RequestAccountDeletionHandler::DAYS);

        $this->db->transaction(function () use ($command, $reason, $on): void {
            $customer = $this->customers->byId($command->customerId) ?? throw new CustomerNotFound($command->customerId);
            $customer->scheduleDeletion($on);

            // Already pending: the date it was given stands, and nothing is sent again.
            if ($customer->pullChanges() === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::deletion('access.customer.deletion_scheduled', $customer, $reason));
            $this->events->dispatch(new CustomerDeletionScheduled((string) Str::uuid(), $customer->id(), CarbonImmutable::now()));

            $dto = $this->mapper->toDto($customer);
            $this->db->afterCommit(fn () => defer(fn () => $this->messages->customerDeletionScheduled($dto, $on)));
        }, 3);
    }
}
