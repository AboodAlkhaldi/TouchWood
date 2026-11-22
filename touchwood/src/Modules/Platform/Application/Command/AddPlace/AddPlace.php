<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddPlace;

/**
 * Add a region, a city or a district (platform.md, stage 7 (a); owner, 2026-10-27 C3). Super Admin only.
 */
final readonly class AddPlace
{
    public function __construct(
        public string $countryCode,
        public string $kind,
        public ?string $parentId,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
