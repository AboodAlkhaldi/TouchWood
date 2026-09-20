<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelAccountDeletion;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The customer stops their own deletion from the account page (spec §1.10). Signing in stops it
 * too, and does so for anyone who signed out; this is for the browser that never left.
 *
 * No password is asked: they are signed in, and the password was asked when the deletion started.
 */
final readonly class CancelAccountDeletionHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws CustomerNotFound|InvalidCustomerStatus
     */
    public function handle(CancelAccountDeletion $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);

        $this->db->transaction(function () use ($customerId): void {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
            $was = $customer->deletionScheduledFor();
            $customer->cancelDeletion();

            if ($customer->pullChanges() === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::deletion('access.customer.deletion_cancelled', $customer, was: $was));
            $this->events->dispatch(new CustomerDeletionCancelled((string) Str::uuid(), $customerId, CarbonImmutable::now()));
        }, 3);
    }
}
