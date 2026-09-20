<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestAccountDeletion;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

use function Illuminate\Support\defer;

/**
 * "Delete my account" (spec §1.10): the password is asked for, as it is for every change to one's
 * own credentials, and guessing it counts towards the same lockout as signing in — a stolen session
 * cannot try without limit.
 *
 * Nothing is destroyed today. The account stops being able to order at once, the customer is told
 * the date and that signing in cancels it, and the sweep anonymizes it fourteen days later.
 */
final readonly class RequestAccountDeletionHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_DELETE;

    /** The fourteen days of spec §1.10, which the owner decided; not a setting. */
    public const int DAYS = 14;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private PasswordPolicy $passwords,
        private SignInLimits $limits,
        private CustomerMapper $mapper,
        private SecurityMessages $messages,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws CustomerNotFound|InvalidAccessAttribute|InvalidCustomerStatus
     */
    public function handle(RequestAccountDeletion $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);
        $customer = $this->customers->find($customerId) ?? throw new CustomerNotFound($customerId);
        $email = $customer->email()->value;

        $this->limits->begin($email, $command->ip);

        if (! $this->passwords->matches($command->password, $customer->passwordHash())) {
            $this->limits->failed($email, $command->ip);

            throw new InvalidAccessAttribute('password', 'not the account\'s password');
        }

        $this->limits->succeeded($email, $command->ip);
        $on = CarbonImmutable::now()->addDays(self::DAYS);

        $this->db->transaction(function () use ($customerId, $on): void {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
            $customer->scheduleDeletion($on);

            if ($customer->pullChanges() === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::deletion('access.customer.deletion_scheduled', $customer));
            $this->events->dispatch(new CustomerDeletionScheduled((string) Str::uuid(), $customerId, CarbonImmutable::now()));

            $dto = $this->mapper->toDto($customer);

            // After the commit and after the answer, never queued: the one message a deletion sends.
            $this->db->afterCommit(fn () => defer(fn () => $this->messages->customerDeletionScheduled($dto, $on)));
        }, 3);
    }
}
