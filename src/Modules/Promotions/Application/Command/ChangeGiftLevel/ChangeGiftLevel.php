<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeGiftLevel;

use Modules\Promotions\Application\Command\GiftLevelFields;

final readonly class ChangeGiftLevel
{
    public function __construct(
        public string $levelId,
        public GiftLevelFields $fields,
    ) {}
}
