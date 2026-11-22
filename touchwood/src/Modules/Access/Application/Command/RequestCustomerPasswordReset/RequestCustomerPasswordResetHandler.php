<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestCustomerPasswordReset;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Connection;
use Modules\Access\Application\Customer\CustomerLinks;
use Modules\Access\Application\Customer\CustomerMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AddressLimits;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\StoreContext;

use function Illuminate\Support\defer;

/**
 * Only an active account gets a link (this store's minutes, 60 by default); a new one replaces the
 * last, and at most a few an hour reach one inbox. Every other case ends quietly, the same way and
 * in the same time: the mail goes out after the answer, so its timing tells nobody which addresses
 * have accounts (the rule the staff reset already follows).
 */
final readonly class RequestCustomerPasswordResetHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_RESET_PASSWORD;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private CustomerTokenRepository $tokens,
        private CustomerSecuritySettings $settings,
        private SecurityMessages $messages,
        private CustomerLinks $links,
        private CustomerMapper $mapper,
        private StoreContext $stores,
        private PlatformApi $platform,
        private AddressLimits $addresses,
        private RateLimiter $limiter,
        private Connection $db,
    ) {}

    public function handle(RequestCustomerPasswordReset $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->addresses->count($command->ip);

        try {
            $email = EmailAddress::of($command->email);
        } catch (InvalidAccessAttribute) {
            return;
        }

        $store = $this->stores->current();
        $storeCode = $this->platform->store($store)?->code;

        if ($storeCode === null) {
            return;
        }

        $this->db->transaction(function () use ($email, $storeCode): void {
            $customer = $this->customers->byEmail($email);

            // A deleted account is nobody: its placeholder address has no owner to write to, and
            // nothing may put a live link on it again (review of step 6).
            if ($customer === null || $customer->isAnonymized() || $customer->status() !== CustomerStatus::Active) {
                return;
            }

            $hourly = 'access:customer-password-reset:'.$customer->id();

            if ($this->limiter->tooManyAttempts($hourly, $this->settings->passwordResetsPerHour())) {
                return;
            }

            $this->limiter->hit($hourly, 3600);

            $link = SecretTokens::issue();
            $this->tokens->putPasswordReset($customer->id(), $link['hash'], CarbonImmutable::now()->addMinutes($this->settings->passwordResetMinutes()));

            $url = $this->links->passwordReset($link['token'], $storeCode, $customer->language()->value);
            $dto = $this->mapper->toDto($customer);

            // After the commit, and after the answer: never queued, since a job would keep the link
            // in the jobs table (spec §2.3).
            $this->db->afterCommit(fn () => defer(fn () => $this->messages->passwordReset($dto->email, $dto->locale, $url)));
        }, 3);
    }
}
