<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\Repository\CustomerRepository;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * What the four staff actions on a customer share (spec §3.3, amendment 43): the reason support was
 * given is required — it is kept only in the audit entry, never on the account — and the store to
 * check the permission in is the customer's **home store**, which is on their own row.
 *
 * Each handler checks its own permission with the scope this hands back, and does its own work in
 * its own transaction.
 */
final readonly class StaffCustomerAction
{
    private const int REASON_MAX = 500;

    public function __construct(
        private CustomerRepository $customers,
    ) {}

    /**
     * @return array{PermissionScope, string} the store to check in, and the reason, trimmed
     *
     * @throws CustomerNotFound|InvalidAccessAttribute
     */
    public function about(string $customerId, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidAccessAttribute('reason', 'required');
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidAccessAttribute('reason', 'at most '.self::REASON_MAX.' characters');
        }

        $customer = $this->customers->find($customerId) ?? throw new CustomerNotFound($customerId);

        return [PermissionScope::store(StoreId::fromString($customer->homeStoreId())), $reason];
    }
}
