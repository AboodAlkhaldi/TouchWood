<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

use DateTimeImmutable;

/** An answered question as the shop reads it. */
final readonly class ShopQuestion
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $storeId,
        public string $body,
        public string $locale,
        public string $answer,
        public DateTimeImmutable $answeredAt,
    ) {}
}
