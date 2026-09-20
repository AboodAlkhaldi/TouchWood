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
use Psr\Log\LoggerInterface;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * The deletions whose fourteen days have passed (spec §1.10, amendment 43). It runs once a day, in
 * the quiet early hours, as the system, under a reserved permission no role can hold.
 *
 * Each account is anonymized in its own transaction, and one that fails is logged and left for
 * tomorrow: it never keeps the others waiting.
 *
 * What goes: the names, the email — replaced by a placeholder that keeps nothing of the old address and
 * frees it for a new account — the phone, every address, and any live code or reset link. What
 * stays: the id, the account type, the home store and the dates, so counts stay honest and the keys
 * other modules hold still resolve.
 */
final readonly class AnonymizeDueAccountsHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_ANONYMIZE;

    /** How many accounts one round reads; it goes round again while any are still due. */
    private const int BATCH = 500;

    /** The stop against a round that never drains: 50,000 accounts in one night is plenty. */
    private const int ROUNDS = 100;

    /**
     * A bcrypt hash of nothing: every password check against it is false, and it is still shaped
     * like a hash, so a hasher that verifies the algorithm first does not throw (review of step 6).
     */
    private const string NO_PASSWORD = '$2y$12$.....................................................';

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
        private AddressRepository $addresses,
        private CustomerTokenRepository $tokens,
        private PlatformApi $platform,
        private LoggerInterface $log,
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

        // A batch at a time until nothing is due, so a backlog is cleared tonight and not fourteen
        // days late; the cap is only a stop against a loop that never drains (review of step 6).
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $due = $this->customers->dueForAnonymizing(CarbonImmutable::now(), self::BATCH);

            if ($due === []) {
                break;
            }

            foreach ($due as $customerId) {
                try {
                    $done += $this->anonymize($customerId);
                } catch (Throwable $failure) {
                    // One account that cannot be anonymized must not keep the others waiting a day.
                    $this->log->error('An account could not be anonymized.', ['customer' => $customerId, 'exception' => $failure]);
                }
            }
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
