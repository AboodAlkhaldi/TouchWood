<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\SwitchMode;

/**
 * A Super Admin flips the store's gateway between test and live (payments.md §1.2; owner, 2026-10-27
 * P17): the store then uses the other key and addresses; attempts already open finish in their own mode.
 */
final readonly class SwitchMode
{
    public function __construct(
        public string $storeId,
        public string $mode,
    ) {}
}
