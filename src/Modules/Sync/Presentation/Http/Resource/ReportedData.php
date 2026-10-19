<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an entry or a read's record is about (sync.md §4.1 Y1 "What" and "Detail"): its kind, the size -
 * product, values, code, or gone - or the provider's code, the offer or discount named, a failed read and
 * its reason, and what the provider sent as kept (`detail`).
 */
#[TypeScript]
final class ReportedData extends Data
{
    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $kind,
        public ?string $code,
        public ?string $variantId,
        public ?bool $sizeExists,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public array $values,
        public ?OfferRefData $offer,
        public ?OfferRefData $discount,
        public ?string $readId,
        public ?FailureData $failure,
        public array $detail,
    ) {}
}
