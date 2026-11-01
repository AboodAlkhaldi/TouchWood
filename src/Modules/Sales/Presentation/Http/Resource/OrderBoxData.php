<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The box suggested for the order's pieces (shipping.md §1.7; gap 5.1), worked out as the page is shown:
 * its name, or pack by hand with why — `MANUAL_SIZE`, `NO_SIZE_FACTS`, `NOTHING_FITS`.
 */
#[TypeScript]
final class OrderBoxData extends Data
{
    public function __construct(
        public ?string $name,
        public bool $byHand,
        public ?string $reason,
    ) {}

    public static function of(?BoxSuggestionDto $box): ?self
    {
        return $box === null ? null : new self($box->boxName, $box->byHand, $box->reason?->value);
    }
}
