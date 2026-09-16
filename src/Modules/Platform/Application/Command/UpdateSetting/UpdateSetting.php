<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UpdateSetting;

final readonly class UpdateSetting
{
    /**
     * @param  string|null  $storeCode  the store for a per-store setting; null for a global one
     */
    public function __construct(
        public string $key,
        public ?string $storeCode,
        public mixed $value,
    ) {}
}
