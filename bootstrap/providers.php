<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\B2B\Infrastructure\B2BServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
    // After Access: B2B depends on it (handoff §4.4).
    B2BServiceProvider::class,
];
