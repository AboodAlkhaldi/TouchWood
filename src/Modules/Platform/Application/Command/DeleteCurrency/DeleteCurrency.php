<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteCurrency;

/**
 * Deletes a currency no store uses, on or off (platform.md §9.7).
 */
final readonly class DeleteCurrency
{
    public function __construct(
        public string $code,
    ) {}
}
