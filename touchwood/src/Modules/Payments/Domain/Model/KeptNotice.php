<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

final readonly class KeptNotice
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public PaymentMode $mode,
        public array $payload,
        public ?DateTimeImmutable $handledAt,
    ) {}
}
