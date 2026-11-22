<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An attempt in the Payments list (payments.md §4.2): its order's number, or none for a try never paid; why it needs
 * attention, in words the page writes from `reasons` (§9.14 #9). No Test badge: the order's number says it (owner,
 * 2026-11-03).
 */
#[TypeScript]
final class PaymentRowData extends Data
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $id,
        public string $storeCode,
        public string $orderId,
        public ?string $orderNumber,
        public ?string $customerName,
        public MoneyData $amount,
        public ?string $method,
        public string $state,
        public string $ownerKind,
        public array $reasons,
        public string $createdAt,
    ) {}
}
