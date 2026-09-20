<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendCustomerEmailVerification;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Connection;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Customer\CustomerLinks;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\CodeRequestTooSoon;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\StoreContext;

/**
 * A new verification link for the customer signed in (spec §1.2), at most a few an hour so nobody
 * can flood their own inbox — or anyone else's, since the address comes from the account. An
 * address already verified is left alone.
 */
final readonly class ResendCustomerEmailVerificationHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_VERIFY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private CustomerSecuritySettings $settings,
        private CustomerLinks $links,
        private CustomerMapper $mapper,
        private SecurityMessages $messages,
        private StoreContext $stores,
        private PlatformApi $platform,
        private RateLimiter $limiter,
        private Connection $db,
    ) {}

    /**
     * @throws CodeRequestTooSoon|CustomerNotFound
     */
    public function handle(ResendCustomerEmailVerification $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id();
        $customer = $this->customers->find($customerId) ?? throw new CustomerNotFound($customerId);

        if ($customer->emailVerifiedAt() !== null) {
            return;
        }

        $hourly = 'access:customer-email-verification:'.$customer->id();

        if ($this->limiter->tooManyAttempts($hourly, $this->settings->passwordResetsPerHour())) {
            throw new CodeRequestTooSoon($this->limiter->availableIn($hourly));
        }

        $this->limiter->hit($hourly, 3600);

        $store = $this->stores->current();
        $storeCode = ($this->platform->store($store) ?? throw new CustomerNotFound($customerId))->code;
        $link = $this->links->emailVerification(
            $customer->id(), $storeCode, $customer->language()->value,
            CarbonImmutable::now()->addHours($this->settings->emailVerificationHours()),
        );
        $dto = $this->mapper->toDto($customer);

        $this->db->afterCommit(fn () => $this->messages->emailVerification($dto, $link));
    }
}
