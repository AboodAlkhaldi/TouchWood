<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewGateway;

/** The store's gateway as its page shows it (payments.md §1.2). */
final readonly class ViewGateway
{
    public function __construct(
        public string $storeId,
    ) {}
}
