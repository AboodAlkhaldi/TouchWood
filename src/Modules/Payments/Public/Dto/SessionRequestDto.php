<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a session is for (payments.md §2.1): `ownerId` is the quote's id at checkout, the request's on a
 * request page; `amount` comes from them, never from the page (rule 12).
 */
final readonly class SessionRequestDto
{
    public function __construct(
        public StoreId $storeId,
        public string $ownerId,
        public string $buyerId,
        public Money $amount,
        public string $locale,
        public DateTimeImmutable $expiresAt,
    ) {}
}
