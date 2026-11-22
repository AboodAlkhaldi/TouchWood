<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * A module's say over a change of one of its settings that the setting's rules cannot judge alone
 * (platform.md §2.2): asked inside the change's transaction, after the stored value is locked and
 * before the new one is saved. Refusing holds the change back, with the module's own error as the
 * note (asked for by Pricing's discount ceiling, owner 2026-10-13).
 */
interface SettingGuard
{
    /**
     * @param  mixed  $from  the value now (the default when none is stored)
     * @param  mixed  $to  the value asked for, already past the setting's type and rules
     *
     * @throws DomainError to hold the change back
     */
    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void;
}
