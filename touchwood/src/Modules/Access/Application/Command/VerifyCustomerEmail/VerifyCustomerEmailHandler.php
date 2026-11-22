<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyCustomerEmail;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Events\CustomerEmailVerified;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The verification link was opened (spec §1.2): the address is verified from that moment. Opening
 * it again changes nothing — the first time stands — so a mail client that follows links twice is
 * harmless. Whoever holds the link may use it, signed in or not (amendment 38).
 */
final readonly class VerifyCustomerEmailHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_VERIFY_EMAIL;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidOrExpiredLink when the link names an account that is not there any more
     */
    public function handle(VerifyCustomerEmail $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $customer = $this->customers->byId($command->customerId) ?? throw new InvalidOrExpiredLink;

            $before = clone $customer;
            $now = CarbonImmutable::now();
            $customer->verifyEmail($now);
            $changed = $customer->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.email_verified', $before, $customer, $changed));
            $this->events->dispatch(new CustomerEmailVerified((string) Str::uuid(), $customer->id(), $now));
        }, 3);
    }
}
