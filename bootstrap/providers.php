<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\Platform\Infrastructure\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
];
