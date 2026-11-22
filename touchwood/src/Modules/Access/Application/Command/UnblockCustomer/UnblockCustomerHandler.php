<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UnblockCustomer;

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
use Modules\Access\Public\Events\CustomerUnblocked;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

/**
 * Staff let a customer back in (spec §1.1, §3.3): the same admin-only action as blocking, in the
 * customer's home store, with the reason kept in the audit log. They must sign in again — the
 * sessions they had were ended while they were blocked.
 */
final readonly class UnblockCustomerHandler
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
    public function handle(UnblockCustomer $command): void
    {
        [$store, $reason] = $this->action->about(self::PERMISSION, $command->customerId, $command->reason);
        $this->authorizer->authorize(self::PERMISSION, $store);

        $this->db->transaction(function () use ($command, $reason): void {
            $customer = $this->customers->byId($command->customerId) ?? throw new CustomerNotFound($command->customerId);
            $customer->unblock();

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::statusChanged('access.customer.unblocked', $customer, $reason));
            $this->events->dispatch(new CustomerUnblocked((string) Str::uuid(), $customer->id(), CarbonImmutable::now()));
        }, 3);
    }
}
