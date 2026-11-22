<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ListPayments;

use InvalidArgumentException;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\PaymentReads;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Public\Contracts\PaymentOrderNumbers;
use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `ListPayments` (payments.md §3, §4.2): those needing attention first, then the rest, newest first, 50 at a time.
 */
final readonly class ListPaymentsHandler
{
    public const string PERMISSION = PaymentsPermissions::VIEW;

    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private PaymentReads $reads,
        private PaymentOrderNumbers $numbers,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput
     */
    public function handle(ListPayments $query): PaymentsPage
    {
        $filters = $this->filters($query);

        if ($query->after !== null && ! Ulids::valid($query->after)) {
            throw new InvalidPaymentsInput('cursor', 'the place the last page gave');
        }

        $after = $query->after === null ? null : strtolower($query->after);
        $rows = $this->reads->rows($filters, $after, self::PER_PAGE + 1);
        $next = count($rows) > self::PER_PAGE ? $rows[self::PER_PAGE - 1]->attemptId : null;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        $attention = $after === null ? $this->reads->needingAttention($filters, self::PER_PAGE) : [];
        $count = $after === null ? ($attention === [] ? 0 : $this->reads->needingAttentionCount($filters)) : 0;
        $numbers = $this->numbers->numbers(array_values(array_unique(array_map(static fn (PaymentRow $row): string => $row->orderId, [...$attention, ...$rows]))));
        $named = static fn (PaymentRow $row): PaymentRow => $row->withOrder($numbers[$row->orderId] ?? null);

        return new PaymentsPage(array_map($named, $attention), $count, array_map($named, $rows), $next);
    }

    /**
     * @throws Unauthorized|InvalidPaymentsInput
     */
    private function filters(ListPayments $query): PaymentFilters
    {
        if ($query->state !== null && AttemptState::tryFrom($query->state) === null) {
            throw new InvalidPaymentsInput('state', 'a payment\'s state');
        }

        if ($query->mode !== null && PaymentMode::tryFrom($query->mode) === null) {
            throw new InvalidPaymentsInput('mode', 'test or live');
        }

        return new PaymentFilters($this->stores($query->storeId), $query->state, $query->mode);
    }

    /**
     * @return list<string>|null
     *
     * @throws Unauthorized|InvalidPaymentsInput
     */
    private function stores(?string $storeId): ?array
    {
        if ($storeId !== null) {
            try {
                $store = StoreId::fromString($storeId);
            } catch (InvalidArgumentException) {
                throw new InvalidPaymentsInput('store', 'not a store id');
            }

            $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

            return [$store->value];
        }

        $held = $this->authorizer->storesWith(self::PERMISSION);

        if ($held === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        return $held === null ? null : array_map(static fn (StoreId $store): string => $store->value, $held);
    }
}
