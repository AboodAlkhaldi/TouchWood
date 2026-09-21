<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyCustomerPhone;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\CustomerPhoneVerification;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Events\CustomerPhoneVerified;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The right code makes the number the account's, verified (spec §1.3): the first one, or the one
 * being changed to — the old number stayed live until this moment. The number is checked again
 * here, because another customer may have taken it while the code was on its way.
 */
final readonly class VerifyCustomerPhoneHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_VERIFY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private CustomerPhoneVerification $verification,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidCode|PhoneAlreadyInUse|CustomerNotFound
     */
    public function handle(VerifyCustomerPhone $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);

        // A wrong code is counted and committed before it is refused: a rolled-back count would let
        // a code be guessed without limit.
        $failure = $this->db->transaction(function () use ($customerId, $command): ?InvalidCode {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
            $now = CarbonImmutable::now();
            $phone = $this->verification->check($customer->id(), $command->code, $now);

            if ($phone instanceof InvalidCode) {
                return $phone;
            }

            if ($this->customers->phoneInUse($phone, $customer->id())) {
                throw new PhoneAlreadyInUse;
            }

            $before = clone $customer;
            $customer->verifyPhone($phone, $now);
            $changed = $customer->pullChanges();

            if ($changed === []) {
                return null;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.phone_verified', $before, $customer, $changed));
            $this->events->dispatch(new CustomerPhoneVerified((string) Str::uuid(), $customer->id(), $now));

            return null;
        }, 3);

        if ($failure !== null) {
            throw $failure;
        }
    }
}
