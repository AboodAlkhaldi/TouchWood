<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SwitchGiftLevel;

final readonly class SwitchGiftLevel
{
    public function __construct(
        public string $levelId,
        public bool $active,
    ) {}
}
