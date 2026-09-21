<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeOwnCustomerPassword;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The customer changes their own password (spec §3.1): the current one is asked for, and guessing
 * it counts towards the same lockout as signing in — a stolen session cannot try without limit.
 * Every other session ends; this one stays.
 */
final readonly class ChangeOwnCustomerPasswordHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private PasswordPolicy $passwords,
        private SignInLimits $limits,
        private CustomerSecuritySettings $settings,
        private CustomerSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidAccessAttribute|CustomerNotFound
     */
    public function handle(ChangeOwnCustomerPassword $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);
        $customer = $this->customers->find($customerId) ?? throw new CustomerNotFound($customerId);
        $email = $customer->email()->value;

        $this->limits->begin($email, $command->ip);

        if (! $this->passwords->matches($command->currentPassword, $customer->passwordHash())) {
            // Not audited, as on the sign-in page: a customer's sign-in events are not (amendment 37c).
            $this->limits->failed($email, $command->ip);

            throw new InvalidAccessAttribute('current_password', 'not the current password');
        }

        $this->limits->succeeded($email, $command->ip);
        $passwordHash = $this->passwords->hashNew($command->newPassword, $this->settings->passwordMinLength());

        $version = $this->db->transaction(function () use ($customerId, $passwordHash): int {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
            $before = clone $customer;
            $customer->changePassword($passwordHash);
            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.password_changed', $before, $customer, $customer->pullChanges()));

            return $customer->sessionVersion();
        }, 3);

        $this->sessions->keep($version);
    }
}
