<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteDraftVariant;

final readonly class DeleteDraftVariant
{
    public function __construct(
        public string $variantId,
    ) {}
}
