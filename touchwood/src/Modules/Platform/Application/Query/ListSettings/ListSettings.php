<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListSettings;

/**
 * The settings screen's read (frontend.md 3.5, E4).
 */
final readonly class ListSettings
{
    public function __construct(
        /** The store the panel is on; null when this person has none, and store settings are then not offered. */
        public ?string $storeId = null,
    ) {}
}
