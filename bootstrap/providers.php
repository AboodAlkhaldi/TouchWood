<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\B2B\Infrastructure\B2BServiceProvider;
use Modules\Catalog\Infrastructure\CatalogServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;
use Modules\Pricing\Infrastructure\PricingServiceProvider;

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
];
