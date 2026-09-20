<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInCustomer;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Customer\GuestVisitors;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\CustomerBlocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCredentials;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Access\Public\Events\GuestBecameCustomer;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\StoreContext;

/**
 * Email and password (spec §1.8): a wrong email and a wrong password are answered the same way, and
 * only the right password learns that an account is blocked — plainly, as the owner decided
 * (2026-09-19). The store they signed in from becomes the one they land in next time, and a guest
 * cart in this browser follows them (spec §1.7).
 */
final readonly class SignInCustomerHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private PasswordPolicy $passwords,
        private SignInLimits $limits,
        private CustomerSessions $sessions,
        private GuestVisitors $guests,
        private StoreContext $stores,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidCredentials|AccountLocked|CustomerBlocked
     */
    public function handle(SignInCustomer $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->limits->begin($command->email, $command->ip);

        $found = $this->account($command->email);

        if (! $this->passwords->matches($command->password, $found?->passwordHash())) {
            // What locked just now is not audited on this side: a customer's sign-ins leave no
            // audit entry at all (amendment 37c) — only their account events do.
            $this->limits->failed($command->email, $command->ip);

            throw new InvalidCredentials;
        }

        $this->limits->succeeded($command->email, $command->ip);

        // A password is stored from registration, so the account is set here.
        if ($found === null) {
            throw new InvalidCredentials;
        }

        $customerId = $found->id();
        $guestId = $this->guests->current();
        $storeId = $this->stores->current()->value;

        $version = $this->db->transaction(function () use ($customerId, $guestId, $storeId): int {
            // Read again, under the row's lock: checking the password takes long enough for an
            // admin to block the account, or a reset to change its password, in between — and this
            // writes the whole row back (review of step 4b).
            $customer = $this->customers->byId($customerId) ?? throw new InvalidCredentials;

            if ($customer->status() !== CustomerStatus::Active) {
                throw new CustomerBlocked;
            }

            $customer->moveToStore($storeId);

            if ($customer->pullChanges() !== []) {
                $this->customers->update($customer);
            }

            if ($guestId !== null) {
                // Sales merges what this browser collected as a guest into their cart (spec §1.7).
                $this->events->dispatch(new GuestBecameCustomer(
                    (string) Str::uuid(), $guestId, $customerId, GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now(),
                ));
            }

            return $customer->sessionVersion();
        }, 3);

        // Only once the change is committed: a rolled-back sign-in must leave no session behind.
        $this->sessions->start($customerId, $version, $command->remember);
    }

    /**
     * The account this email names, read before the password is checked. It is used for nothing but
     * the password and the id: the row that is written is read again inside the transaction.
     */
    private function account(string $email): ?Customer
    {
        try {
            return $this->customers->byEmail(EmailAddress::of($email));
        } catch (InvalidAccessAttribute) {
            return null;
        }
    }
}
