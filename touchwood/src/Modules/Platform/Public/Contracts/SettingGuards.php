<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Where a module registers a guard over one of its own settings, at boot (platform.md §2.2).
 */
interface SettingGuards
{
    /**
     * @param  string  $key  a setting the module declared
     * @param  class-string<SettingGuard>  $guard  resolved from the container when a change is asked
     */
    public function register(string $key, string $guard): void;
}
