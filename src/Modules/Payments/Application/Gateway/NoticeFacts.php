<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

/**
 * What a kept notice names, all of it signed (notes §5.4): only ever a reason to ask (rule 1).
 */
final readonly class NoticeFacts
{
    public function __construct(
        public ?string $attemptId,
        public ?string $invoiceId,
        public ?string $paymentId,
    ) {}
}
