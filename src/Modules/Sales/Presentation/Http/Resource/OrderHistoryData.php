<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One change in an order's history (§5): its kind, when, by whom, its codes and ids. */
#[TypeScript]
final class OrderHistoryData extends Data
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $kind,
        public string $at,
        public ?string $by,
        public array $detail,
    ) {}
}
