<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use Carbon\CarbonImmutable;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Sales\Application\Query\OrderShippingView;
use Modules\Sales\Domain\Service\OrderStatuses;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/** What staff's reads share: customers by name only, staff by the name the reader may see, a currency's exponent. */
final readonly class StaffReader
{
    public function __construct(
        private AccessApi $access,
        private StaffNames $staffNames,
        private PlatformApi $platform,
        private PaymentsApi $payments,
    ) {}

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerName>
     */
    public function customers(array $customerIds): array
    {
        $ids = array_values(array_unique($customerIds));
        $found = $ids === [] ? [] : $this->access->customers($ids);
        $names = [];

        foreach ($ids as $id) {
            $names[$id] = CustomerName::of($id, $found[$id] ?? null);
        }

        return $names;
    }

    /**
     * A row of `StaffReads::orders` as staff's lists show it — ListOrders and the customer page's orders.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $hours  each store's hours for checking a transfer (`transferHours`)
     */
    public function orderRow(array $row, CustomerName $customer, array $hours = []): StaffOrderRow
    {
        $payment = OrderStatuses::payment((int) $row['due_minor'], (int) $row['paid_minor'], (int) $row['refunded_minor']);

        return new StaffOrderRow(
            (string) $row['id'], (string) $row['number'], (string) $row['store_id'], CarbonImmutable::parse((string) $row['placed_at']),
            $customer, (string) $row['status'], (string) $row['shipment_status'], $payment,
            OrderStatuses::customer((string) $row['status'], (string) $row['shipment_status'], $payment),
            Money::of((int) $row['order_total_minor'], (string) $row['currency_code']), $this->exponent((string) $row['currency_code']),
            $row['short_of_stock_at'] !== null, (bool) $row['may_ship_unpaid'], OrderShippingView::feeOpen($row), (bool) $row['is_test'],
            isset($hours[(string) $row['store_id']]) && OrderStatuses::transferOverdue(
                (string) $row['way_to_pay'], (bool) $row['is_test'], (int) $row['due_minor'], (int) $row['paid_minor'] - (int) $row['refunded_minor'],
                $row['first_transfer_at'] === null ? null : CarbonImmutable::parse((string) $row['first_transfer_at']), $hours[(string) $row['store_id']], CarbonImmutable::now(),
            ),
        );
    }

    /**
     * Each store's hours for checking a transfer, for the rows that hold a receipt: one read per store, not per row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function transferHours(array $rows): array
    {
        $hours = [];

        foreach ($rows as $row) {
            if ($row['first_transfer_at'] !== null && ! isset($hours[(string) $row['store_id']])) {
                $hours[(string) $row['store_id']] = $this->payments->transferCheckHours(StoreId::fromString((string) $row['store_id']));
            }
        }

        return $hours;
    }

    /**
     * One customer by name, and whether the reader may view them — read their email (access.md amendment
     * 72(e), as Loyalty's `CustomerName`): every store, or the customer's home store among those where they
     * hold the job.
     *
     * @return array{CustomerName, bool}
     */
    public function customer(string $customerId): array
    {
        $customer = $this->access->customers([$customerId])[$customerId] ?? null;
        $stores = $this->access->customerViewStores();
        $mayView = $customer !== null && ($stores === null
            || in_array(strtolower($customer->homeStoreId), array_map(static fn (StoreId $held): string => $held->value, $stores), true));

        return [CustomerName::of($customerId, $customer), $mayView];
    }

    /**
     * @param  list<?string>  $staffIds
     * @return array<string, string>
     */
    public function staff(array $staffIds): array
    {
        $ids = array_values(array_unique(array_filter($staffIds, static fn (?string $id): bool => $id !== null)));
        $names = [];

        foreach ($ids === [] ? [] : $this->staffNames->forReader($ids) as $id => $name) {
            $names[$id] = $name->name;
        }

        return $names;
    }

    public function exponent(string $currencyCode): int
    {
        return $this->platform->currency($currencyCode)->exponent ?? 2;
    }
}
