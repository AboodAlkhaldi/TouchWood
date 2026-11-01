<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use Carbon\CarbonImmutable;
use Modules\Access\Public\Contracts\AccessApi;
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
     */
    public function orderRow(array $row, CustomerName $customer): StaffOrderRow
    {
        $payment = OrderStatuses::payment((int) $row['due_minor'], (int) $row['paid_minor'], (int) $row['refunded_minor']);

        return new StaffOrderRow(
            (string) $row['id'], (string) $row['number'], (string) $row['store_id'], CarbonImmutable::parse((string) $row['placed_at']),
            $customer, (string) $row['status'], (string) $row['shipment_status'], $payment,
            OrderStatuses::customer((string) $row['status'], (string) $row['shipment_status'], $payment),
            Money::of((int) $row['order_total_minor'], (string) $row['currency_code']), $this->exponent((string) $row['currency_code']),
            $row['short_of_stock_at'] !== null, (bool) $row['may_ship_unpaid'], OrderShippingView::feeOpen($row),
        );
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
