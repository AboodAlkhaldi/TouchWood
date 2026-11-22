<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateGiftLevel;

use Modules\Promotions\Application\Command\GiftLevelFields;

final readonly class CreateGiftLevel
{
    public function __construct(
        public string $storeId,
        public GiftLevelFields $fields,
        public bool $active = true,
    ) {}
}
