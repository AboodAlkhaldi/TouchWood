<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * A store's gateway (payments.md §1.2): MyFatoorah, test or live, on or off. Whether MyFatoorah refuses
 * it is its current key's (`GatewayKey::$rejected`).
 */
final readonly class Gateway
{
    public function __construct(
        public string $storeId,
        public bool $active,
        public PaymentMode $mode,
    ) {}

    public function switchedTo(bool $active): self
    {
        return new self($this->storeId, $active, $this->mode);
    }

    public function inMode(PaymentMode $mode): self
    {
        return new self($this->storeId, $this->active, $mode);
    }
}
