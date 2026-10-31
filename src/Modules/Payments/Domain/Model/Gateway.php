<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * A store's gateway (payments.md §1.2): MyFatoorah, test or live, on or off; `keyRejected` while
 * MyFatoorah refuses the current key, until a new one is set.
 */
final readonly class Gateway
{
    public function __construct(
        public string $storeId,
        public bool $active,
        public PaymentMode $mode,
        public bool $keyRejected,
    ) {}
}
