<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteWordPair;

final readonly class DeleteWordPair
{
    public function __construct(
        public string $pairId,
    ) {}
}
