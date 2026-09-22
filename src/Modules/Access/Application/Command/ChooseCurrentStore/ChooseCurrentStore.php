<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChooseCurrentStore;

/**
 * The store this staff member is working in from now on (stage 2b, P3). The admin panel carries no
 * store in its URLs, so the choice is remembered on the account and follows them between machines
 * (owner, 2026-09-19).
 */
final readonly class ChooseCurrentStore
{
    public function __construct(
        public string $storeId,
    ) {}
}
