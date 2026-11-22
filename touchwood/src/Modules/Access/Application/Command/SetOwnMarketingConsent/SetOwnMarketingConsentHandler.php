<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetOwnMarketingConsent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The customer turns offers and news on or off for themselves (amendment 79). The id comes from who
 * is signed in, never from the request; staff never change it.
 */
final readonly class SetOwnMarketingConsentHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws CustomerNotFound
     */
    public function handle(SetOwnMarketingConsent $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);

        $this->db->transaction(function () use ($customerId, $command): void {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);

            $before = clone $customer;
            $now = CarbonImmutable::now();
            $customer->changeMarketingConsent($command->consent, MarketingConsentSource::Account, $now);
            $changed = $customer->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.marketing_consent_changed', $before, $customer, $changed));
            $this->events->dispatch(new MarketingConsentChanged((string) Str::uuid(), $customer->id(), $now));
        }, 3);
    }
}
