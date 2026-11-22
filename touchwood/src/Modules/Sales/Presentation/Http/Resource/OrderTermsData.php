<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The terms an order was placed under, on its page (flags-2026-11.md S3.3, F16): "Terms v3, accepted 18 Nov 2026
 * 14:02", linking to that version's text in the shop: where it was read in the order's store, in the reader's language,
 * with `?version=` and `?policy=`. The link stays once the policy is switched off there, whose page then opens labelled
 * as old (the owner's decisions of 2026-11-20); null only for a version never in force in that store.
 */
#[TypeScript]
final class OrderTermsData extends Data
{
    public function __construct(
        public int $version,
        /** In UTC, for the page to show in the store's zone. */
        public string $acceptedAt,
        public ?string $url,
        /** The Returns and Refunds policy ticked with it, when the store had one. */
        public ?int $returnsVersion,
        public ?string $returnsUrl,
    ) {}
}
