<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\BlockCustomer;

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
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

/**
 * Staff block a customer (spec §1.1, §3.3): they cannot sign in, and every session of theirs ends
 * at its next request, because each one checks the account's status. An admin-only action in the
 * customer's home store (amendment 43), with the reason kept in the audit log.
 *
 * A pending deletion is untouched: the two are separate, and a blocked account's deletion still
 * runs (spec §4.1).
 */
final readonly class BlockCustomerHandler
{
    public const string PERMISSION = AccessPermissions::CUSTOMER_BLOCK;

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
    public function handle(BlockCustomer $command): void
    {
        [$store, $reason] = $this->action->about($command->customerId, $command->reason);
        $this->authorizer->authorize(self::PERMISSION, $store);

        $this->db->transaction(function () use ($command, $reason): void {
            $customer = $this->customers->byId($command->customerId) ?? throw new CustomerNotFound($command->customerId);
            $customer->block();
            $customer->pullChanges();

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::statusChanged('access.customer.blocked', $customer, $reason));
            $this->events->dispatch(new CustomerBlocked((string) Str::uuid(), $customer->id(), CarbonImmutable::now()));
        }, 3);
    }
}
