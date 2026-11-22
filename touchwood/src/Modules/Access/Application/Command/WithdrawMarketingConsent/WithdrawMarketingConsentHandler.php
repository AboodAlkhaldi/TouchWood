<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\WithdrawMarketingConsent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * An unsubscribe Ops received (amendment 79): the system withdraws the customer's marketing
 * consent. An unknown or anonymized account, or consent already off, changes nothing — an
 * unsubscribe link may be followed twice, and the provider may tell us more than once.
 */
final readonly class WithdrawMarketingConsentHandler
{
    public const string PERMISSION = AccessPermissions::MARKETING_CONSENT_WITHDRAW;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidAccessAttribute when the source would give consent
     */
    public function handle(WithdrawMarketingConsent $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        // A Super Admin holds every reserved permission too; this one is the system's alone (staff
        // never change a customer's consent) - the console, or Ops' job that an unsubscribe link or
        // the newsletter provider's call queued, on a guest's or a customer's behalf (amendment 79,
        // as built).
        $this->rules->requireSystemForVisitor(self::PERMISSION);

        if (! in_array($command->source, MarketingConsentSource::withdrawals(), true)) {
            throw new InvalidAccessAttribute('marketing_consent', 'an unsubscribe only withdraws consent');
        }

        $this->db->transaction(function () use ($command): void {
            $customer = $this->customers->byId($command->customerId);

            if ($customer === null || $customer->isAnonymized()) {
                return;
            }

            $before = clone $customer;
            $now = CarbonImmutable::now();
            $customer->changeMarketingConsent(false, $command->source, $now);
            $changed = $customer->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.marketing_consent_withdrawn', $before, $customer, $changed));
            $this->events->dispatch(new MarketingConsentChanged((string) Str::uuid(), $customer->id(), $now));
        }, 3);
    }
}
