<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrderStores;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/** A store the order and return screens' store filter offers; an off one marked Off (sales.md §1.12). */
final readonly class OrderStoreChoice
{
    public function __construct(
        public string $storeId,
        public string $code,
        public TranslatedTextDto $name,
        public bool $isActive,
    ) {}
}
