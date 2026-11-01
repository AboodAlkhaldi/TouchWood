<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * A store's gateway (payments.md §1.2): MyFatoorah, test or live, on or off. Whether MyFatoorah refuses
 * it is its current key's (`GatewayKey::$rejected`). Apple Pay is registered per domain and mode (gap 8.5).
 */
final readonly class Gateway
{
    /**
     * @param  list<array{domain: string, mode: string}>  $applePayDomains
     */
    public function __construct(
        public string $storeId,
        public bool $active,
        public PaymentMode $mode,
        public array $applePayDomains = [],
    ) {}

    public function switchedTo(bool $active): self
    {
        return new self($this->storeId, $active, $this->mode, $this->applePayDomains);
    }

    public function inMode(PaymentMode $mode): self
    {
        return new self($this->storeId, $this->active, $mode, $this->applePayDomains);
    }

    public function applePayOn(string $domain, PaymentMode $mode): bool
    {
        return in_array(['domain' => $domain, 'mode' => $mode->value], $this->applePayDomains, true);
    }

    public function withApplePayOn(string $domain, PaymentMode $mode): self
    {
        return new self($this->storeId, $this->active, $this->mode, [...$this->applePayDomains, ['domain' => $domain, 'mode' => $mode->value]]);
    }
}
