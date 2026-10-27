<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Sales\Application\Query\ListCustomerOrders\ListCustomerOrdersHandler;
use Shared\Application\Unauthorized;

/**
 * The customer's latest orders on the staff customer page (sales.md §3.2, §4.1 P7; access.md amendment 72(a)),
 * drawn by `resources/js/sections/customer/sales-orders.tsx`: no section at all for a reader who views no
 * orders; an empty list for a customer with none they may see.
 */
final readonly class OrdersCustomerSection implements CustomerPageSection
{
    public function __construct(
        private OrderPages $pages,
        private ListCustomerOrdersHandler $handler,
    ) {}

    public function sectionFor(string $customerId): ?array
    {
        try {
            // Plain arrays all the way down, as the section's contract hands them on: the totals are Platform's DTOs.
            /** @var array<string, mixed> */
            return json_decode($this->pages->customerSection($customerId, $this->handler)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Unauthorized) {
            return null;
        }
    }
}
