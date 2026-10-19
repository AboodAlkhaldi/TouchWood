<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\ReviewMark;

/** An approved review as the shop reads it. */
final readonly class ShopReview
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $variantId,
        public int $rating,
        public string $body,
        public string $locale,
        public ReviewMark $mark,
        public DateTimeImmutable $writtenAt,
    ) {}
}
