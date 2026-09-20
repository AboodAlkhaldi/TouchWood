<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RegisterCustomer;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CustomerLinks;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\EmailAlreadyRegistered;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Events\CustomerRegistered;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\StoreContext;

/**
 * A new customer account (spec §1.2): active at once, with the email unverified and no phone yet,
 * so they can browse and fill a cart; ordering waits for both verifications. The store they
 * registered in becomes their home store, fixed, and that store's terms version is recorded
 * (amendment 37). An email that already belongs to an account — a customer's or a staff member's,
 * since an email belongs to one of them and never both (amendment 13) — is answered plainly and in
 * the same words, so the form never says who works here.
 */
final readonly class RegisterCustomerHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_REGISTER;

    private const int NAME_MAX = 100;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private StaffUserRepository $staff,
        private PasswordPolicy $passwords,
        private CustomerSecuritySettings $settings,
        private CustomerLinks $links,
        private CustomerMapper $mapper,
        private SecurityMessages $messages,
        private StoreContext $stores,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @return string the new customer's id
     *
     * @throws EmailAlreadyRegistered|InvalidAccessAttribute
     */
    public function handle(RegisterCustomer $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $email = EmailAddress::of($command->email);
        $language = Language::of($command->locale);
        $accountType = AccountType::tryFrom(strtoupper(trim($command->accountType)))
            ?? throw new InvalidAccessAttribute('account_type', 'individual or company');
        $firstName = $this->name('first_name', $command->firstName);
        $lastName = $this->name('last_name', $command->lastName);

        if (! $command->termsAccepted) {
            throw new InvalidAccessAttribute('terms', 'the terms and privacy policy must be accepted');
        }

        // The slow parts — the breach service and the hasher — only once the rest is in order.
        $passwordHash = $this->passwords->hashNew($command->password, $this->settings->passwordMinLength());
        $store = $this->stores->current();
        $storeId = $store->value;
        $storeCode = ($this->platform->store($store) ?? throw new InvalidAccessAttribute('store', 'unknown'))->code;
        $termsVersion = $this->settings->termsVersion();
        $verificationHours = $this->settings->emailVerificationHours();

        return $this->db->transaction(function () use ($email, $language, $accountType, $firstName, $lastName, $passwordHash, $storeId, $storeCode, $termsVersion, $verificationHours): string {
            // One email, one account (amendment 13) — and a staff address is answered exactly like a
            // customer's, so this public form never tells a stranger who works here (owner,
            // 2026-09-20, after the step 4a review).
            if ($this->customers->emailInUse($email) || $this->staff->emailInUse($email)) {
                throw new EmailAlreadyRegistered;
            }

            $now = CarbonImmutable::now();
            $customer = Customer::register(
                $this->customers->nextId(), $email, $passwordHash, $firstName, $lastName,
                $accountType, $language, $storeId, $termsVersion, $now,
            );

            $this->customers->add($customer);
            $this->platform->recordAudit(CustomerAudit::registered($customer));
            $this->events->dispatch(new CustomerRegistered((string) Str::uuid(), $customer->id(), $accountType, $storeId, $now));

            $link = $this->links->emailVerification($customer->id(), $storeCode, $language->value, $now->addHours($verificationHours));
            $dto = $this->mapper->toDto($customer);

            // Sent once the transaction commits, never queued: a job would keep the link in the
            // jobs table (spec §2.3).
            $this->db->afterCommit(fn () => $this->messages->emailVerification($dto, $link));

            return $customer->id();
        }, 3);
    }

    /**
     * @throws InvalidAccessAttribute
     */
    private function name(string $attribute, string $value): string
    {
        $name = trim($value);

        return match (true) {
            $name === '' => throw new InvalidAccessAttribute($attribute, 'required'),
            mb_strlen($name) > self::NAME_MAX => throw new InvalidAccessAttribute($attribute, 'at most '.self::NAME_MAX.' characters'),
            default => $name,
        };
    }
}
