<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewAttempt;

use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\PaymentReads;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Public\Contracts\PaymentOrderNumbers;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `ViewAttempt` (payments.md §3, §4.2): its transactions and calls; the customer's details only for staff who may read
 * customers in that store.
 */
final readonly class ViewAttemptHandler
{
    public const string PERMISSION = PaymentsPermissions::VIEW;

    /** Access's own; a word only, as Payments takes nothing else of Access. */
    private const string CUSTOMER_VIEW = 'access.customer.view';

    public function __construct(
        private Authorizer $authorizer,
        private PaymentReads $reads,
        private PaymentOrderNumbers $numbers,
    ) {}

    /**
     * @throws Unauthorized|AttemptNotFound
     */
    public function handle(ViewAttempt $query): AttemptView
    {
        $id = strtolower($query->attemptId);
        $row = Ulids::valid($id) ? $this->reads->row($id) : null;

        if ($row === null) {
            throw new AttemptNotFound;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($row->storeId)));
        $number = $this->numbers->numbers([$row->orderId])[$row->orderId] ?? null;

        return new AttemptView(
            $row->withOrder($number),
            $this->canReadCustomers($row->storeId) ? $this->reads->contact($id) : null,
            $this->reads->transactions($id),
            $this->reads->calls($id),
            AttemptState::from($row->state)->isLive(),
        );
    }

    private function canReadCustomers(string $storeId): bool
    {
        $stores = $this->authorizer->storesWith(self::CUSTOMER_VIEW);

        return $stores === null || array_filter($stores, static fn (StoreId $store): bool => $store->value === $storeId) !== [];
    }
}
