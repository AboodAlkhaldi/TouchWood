<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RenamePlace;

/**
 * Rename a place, in both languages (owner, 2026-10-27 C3). Super Admin only.
 */
final readonly class RenamePlace
{
    public function __construct(
        public string $placeId,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
