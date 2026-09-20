<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\AnonymizeDueAccounts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The deletions whose fourteen days have passed (spec §1.10, amendment 43). It runs once a day, in
 * the quiet early hours, as the system, under a reserved permission no role can hold.
 *
 * Each account is anonymized in its own transaction, so one that fails leaves the others done. What
 * goes: the names, the email — replaced by a placeholder that keeps nothing of the old address and
 * frees it for a new account — the phone, every address, and any live code or reset link. What
 * stays: the id, the account type, the home store and the dates, so counts stay honest and the keys
 * other modules hold still resolve.
 */
final readonly class AnonymizeDueAccountsHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_ANONYMIZE;

    /** Anonymizing is not urgent: a day's worth at a time, and the rest tomorrow. */
    private const int BATCH = 500;

    /** A hash no password can produce, so nothing can ever match it again. */
    private const string NO_PASSWORD = 'anonymized';

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private AddressRepository $addresses,
        private CustomerTokenRepository $tokens,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @return int how many accounts were anonymized
     */
    public function handle(AnonymizeDueAccounts $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $done = 0;

        foreach ($this->customers->dueForAnonymizing(CarbonImmutable::now(), self::BATCH) as $customerId) {
            $done += $this->anonymize($customerId);
        }

        return $done;
    }

    private function anonymize(string $customerId): int
    {
        return $this->db->transaction(function () use ($customerId): int {
            $customer = $this->customers->byId($customerId);

            // Read again under the lock: they may have signed in, or support may have stopped it,
            // while this sweep was working through the others.
            if ($customer === null || $customer->isAnonymized() || $customer->deletionScheduledFor() === null
                || $customer->deletionScheduledFor() > CarbonImmutable::now()) {
                return 0;
            }

            $customer->anonymize(
                EmailAddress::of("deleted-{$customerId}@deleted.invalid"),
                self::NO_PASSWORD,
                CarbonImmutable::now(),
            );
            $customer->pullChanges();

            $this->customers->update($customer);
            $this->addresses->deleteForCustomer($customerId);
            $this->tokens->deletePasswordReset($customerId);
            $this->tokens->deletePhoneCode($customerId);
            $this->platform->recordAudit(CustomerAudit::anonymized($customer));
            $this->events->dispatch(new CustomerAnonymized((string) Str::uuid(), $customerId, CarbonImmutable::now()));

            return 1;
        }, 3);
    }
}
