<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\CustomerRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What the four staff actions on a customer share (spec §3.3, amendment 43): the reason support was
 * given is required — it is kept only in the audit entry, never on the account — and the store to
 * check the permission in is the customer's **home store**, which is on their own row.
 *
 * A staff member who may act on customers somewhere, but not on this one, is told the same thing as
 * for an id that never existed: the panel never confirms which ids are real (review of step 6).
 * Someone who may not act on customers at all is refused plainly, by the handler's own check.
 *
 * Each handler checks its own permission with the scope this hands back, and does its own work in
 * its own transaction.
 */
final readonly class StaffCustomerAction
{
    private const int REASON_MAX = 500;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
    ) {}

    /**
     * @return array{PermissionScope, string} the store to check in, and the reason, trimmed
     *
     * @throws CustomerNotFound|InvalidAccessAttribute|Unauthorized
     */
    public function about(string $permission, string $customerId, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidAccessAttribute('reason', 'required');
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidAccessAttribute('reason', 'at most '.self::REASON_MAX.' characters');
        }

        // It is written into the audit log, which refuses anything that is not text: a control
        // character or a byte that is not UTF-8 would be a failed insert instead of a clear answer.
        if (! mb_check_encoding($reason, 'UTF-8') || preg_match('/\p{Cc}/u', $reason) === 1) {
            throw new InvalidAccessAttribute('reason', 'one line of text');
        }

        $stores = $this->authorizer->storesWith($permission);
        $customer = $stores === [] ? null : $this->customers->find($customerId);

        // Nothing is read for someone who may not act on customers anywhere: they are refused here,
        // by the name of the action they lack, exactly as their handler's own check would refuse
        // them a moment later (review of step 7).
        if ($stores === []) {
            throw new Unauthorized($permission);
        }

        if ($customer === null) {
            throw new CustomerNotFound($customerId);
        }

        $home = StoreId::fromString($customer->homeStoreId());

        if ($stores !== null && ! $this->covers($stores, $home)) {
            throw new CustomerNotFound($customerId);
        }

        return [PermissionScope::store($home), $reason];
    }

    /**
     * @param  list<StoreId>  $stores
     */
    private function covers(array $stores, StoreId $home): bool
    {
        foreach ($stores as $store) {
            if ($store->equals($home)) {
                return true;
            }
        }

        return false;
    }
}
