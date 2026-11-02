<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Modules\Payments\Public\Enums\PaymentMode;
use SensitiveParameter;

/**
 * Who a call speaks as: the key it uses and the address of its store and mode, with the attempt it belongs
 * to when it has one (payments.md §1.1 rule 13). Made per call, kept nowhere.
 */
final readonly class GatewayAccess
{
    public function __construct(
        public string $storeId,
        public PaymentMode $mode,
        public string $apiBase,
        public string $keyId,
        #[SensitiveParameter]
        public string $key,
        public ?string $attemptId = null,
    ) {}

    public function forAttempt(string $attemptId): self
    {
        return new self($this->storeId, $this->mode, $this->apiBase, $this->keyId, $this->key, $attemptId);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['storeId' => $this->storeId, 'mode' => $this->mode->value, 'keyId' => $this->keyId];
    }
}
