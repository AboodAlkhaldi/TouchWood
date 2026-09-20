<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestCustomerPhoneCode;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\CustomerPhoneVerification;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A code to the number the customer entered — their first, or one they are changing to (spec §1.3).
 * A number another customer already uses is refused now, when it is entered, not after the code.
 * The account keeps its current number until the code is right.
 */
final readonly class RequestCustomerPhoneCodeHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_VERIFY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private CustomerPhoneVerification $verification,
        private Connection $db,
    ) {}

    /**
     * @throws PhoneAlreadyInUse|CustomerNotFound
     */
    public function handle(RequestCustomerPhoneCode $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id();
        $phone = PhoneNumber::of($command->phone);

        $this->db->transaction(function () use ($customerId, $phone): void {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);

            if ($this->customers->phoneInUse($phone, $customer->id())) {
                throw new PhoneAlreadyInUse;
            }

            $purpose = $customer->phone() === null ? CustomerPhoneCodePurpose::Add : CustomerPhoneCodePurpose::Change;
            $this->verification->send($customer->id(), $phone, $purpose, $customer->language(), CarbonImmutable::now());
        }, 3);
    }
}
