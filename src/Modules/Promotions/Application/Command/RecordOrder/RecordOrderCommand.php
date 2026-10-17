<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RecordOrder;

use Modules\Promotions\Public\Dto\RecordOrder;

final readonly class RecordOrderCommand
{
    public function __construct(
        public RecordOrder $change,
    ) {}
}
