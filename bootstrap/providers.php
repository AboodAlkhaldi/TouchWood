<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\B2B\Infrastructure\B2BServiceProvider;
use Modules\Catalog\Infrastructure\CatalogServiceProvider;
use Modules\Loyalty\Infrastructure\LoyaltyServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
    // After Access: B2B depends on it (handoff §4.4).
    B2BServiceProvider::class,
    // After Access too: Catalog declares its permissions through it (handoff §4.4, 2026-10-02).
    CatalogServiceProvider::class,
    // After Access too: Loyalty declares its permissions through it, and depends on Platform and
    // Access only (handoff §4.4).
    LoyaltyServiceProvider::class,
];
