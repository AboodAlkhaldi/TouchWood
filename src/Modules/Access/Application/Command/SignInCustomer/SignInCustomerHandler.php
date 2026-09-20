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

        $customer = $this->account($command->email);

        if (! $this->passwords->matches($command->password, $customer?->passwordHash())) {
            $this->limits->failed($command->email, $command->ip);

            throw new InvalidCredentials;
        }

        $this->limits->succeeded($command->email, $command->ip);

        // A password is stored from registration, so $customer is set here.
        if ($customer === null) {
            throw new InvalidCredentials;
        }

        if ($customer->status() !== CustomerStatus::Active) {
            throw new CustomerBlocked;
        }

        $guestId = $this->guests->current();
        $storeId = $this->stores->current()->value;

        $this->db->transaction(function () use ($customer, $command, $guestId, $storeId): void {
            $customer->moveToStore($storeId);

            if ($customer->pullChanges() !== []) {
                $this->customers->update($customer);
            }

            $this->sessions->start($customer->id(), $customer->sessionVersion(), $command->remember);

            if ($guestId !== null) {
                // Sales merges what this browser collected as a guest into their cart (spec §1.7).
                $this->events->dispatch(new GuestBecameCustomer(
                    (string) Str::uuid(), $guestId, $customer->id(), GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now(),
                ));
            }
        }, 3);
    }

    private function account(string $email): ?Customer
    {
        try {
            return $this->customers->byEmail(EmailAddress::of($email));
        } catch (InvalidAccessAttribute) {
            return null;
        }
    }
}
