<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\B2B\Infrastructure\B2BServiceProvider;
use Modules\Catalog\Infrastructure\CatalogServiceProvider;
use Modules\Feedback\Infrastructure\FeedbackServiceProvider;
use Modules\Inventory\Infrastructure\InventoryServiceProvider;
use Modules\Loyalty\Infrastructure\LoyaltyServiceProvider;
use Modules\Ops\Infrastructure\OpsServiceProvider;
use Modules\Payments\Infrastructure\PaymentsServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;
use Modules\Pricing\Infrastructure\PricingServiceProvider;
use Modules\Promotions\Infrastructure\PromotionsServiceProvider;
use Modules\Sales\Infrastructure\SalesServiceProvider;
use Modules\Shipping\Infrastructure\ShippingServiceProvider;
use Modules\Sync\Infrastructure\SyncServiceProvider;

return [
    AppServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
    // After Access: B2B depends on it (handoff §4.4).
    B2BServiceProvider::class,
    // After Access too: Catalog declares its permissions through it (handoff §4.4, 2026-10-02).
    CatalogServiceProvider::class,
    // After Catalog: Pricing prices Catalog's variants and declares its permissions through Access
    // (handoff §4.4, pricing.md §2.4).
    PricingServiceProvider::class,
    // After Catalog: Inventory counts Catalog's variants, declares its permissions through Access
    // and its default threshold through Platform (handoff §4.4, inventory.md §2.4).
    InventoryServiceProvider::class,
    // After Pricing and Inventory: Sync fills their provider contracts, and declares its permissions
    // through Access (handoff §4.4, sync.md §2.3).
    SyncServiceProvider::class,
    // After Access too: Loyalty declares its permissions through it, and depends on Platform and
    // Access only (handoff §4.4).
    LoyaltyServiceProvider::class,
    // After Catalog, Pricing and Loyalty: Promotions reads Catalog's lines, sits on Pricing's
    // ceiling, and declares its permissions through Access (handoff §4.4, promotions.md §2).
    PromotionsServiceProvider::class,
    // Sales's public contract only, for now: Feedback is built against it before Sales (feedback.md
    // §2.1). Sales's build fills this provider in.
    SalesServiceProvider::class,
    // After Access, Catalog and Sales: Feedback declares its permissions through Access, reads Catalog
    // and asks Sales for the verified purchase (handoff §4.4, feedback.md §2.3).
    FeedbackServiceProvider::class,
    // After Platform, Access and Catalog: Shipping declares its permissions through Access and its
    // say over a store's ship-to countries through Platform (handoff §4.4, shipping.md §1.3).
    ShippingServiceProvider::class,
    // After Platform and Access: Payments declares its permissions through Access and its settings
    // and their guard through Platform (handoff §4.4, payments.md §2.3).
    PaymentsServiceProvider::class,
    // Last: Ops reads every module's public surface and declares its permissions through Access;
    // nothing depends on it (handoff §4.4, ops.md §2).
    OpsServiceProvider::class,
];
