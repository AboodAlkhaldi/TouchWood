<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** `/admin/payments` (payments.md §4.2, Q19; §9.14 #10): needing attention first, then the rest, 50 at a time. */
#[TypeScript]
final class PaymentsListPage extends Data
{
    /**
     * @param  list<GatewayStoreData>  $stores
     * @param  list<string>  $states
     * @param  list<PaymentRowData>  $needingAttention
     * @param  list<PaymentRowData>  $rows
     */
    public function __construct(
        public array $stores,
        public ?string $storeCode,
        public ?string $state,
        public ?string $mode,
        public array $states,
        public array $needingAttention,
        public int $needingAttentionCount,
        public array $rows,
        public ?string $next,
        public string $timezone,
    ) {}
}
