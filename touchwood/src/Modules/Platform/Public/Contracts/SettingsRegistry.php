<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\SettingDefinitionDto;

/**
 * Where modules declare their settings, once, at boot (Platform spec §2.2).
 */
interface SettingsRegistry
{
    /**
     * A malformed, foreign or duplicate key, or a default that fails its own rules, is a
     * programming error and throws at boot.
     *
     * @param  string  $module  the declaring module, e.g. "loyalty"; every key must start with it
     *
     * @throws \LogicException
     */
    public function define(string $module, SettingDefinitionDto ...$definitions): void;
}
