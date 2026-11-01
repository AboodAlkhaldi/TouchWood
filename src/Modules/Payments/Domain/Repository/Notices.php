<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

interface Notices
{
    /**
     * Keeps a notice whose signature checked out; false when this one was kept before (MyFatoorah sends
     * again until it hears 200).
     *
     * @param  array<array-key, mixed>  $payload  the card and contact details already removed
     */
    public function add(string $storeId, PaymentMode $mode, string $reference, array $payload, DateTimeImmutable $now): bool;
}
