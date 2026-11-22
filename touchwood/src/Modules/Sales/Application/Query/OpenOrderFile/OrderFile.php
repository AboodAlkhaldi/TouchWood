<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\OpenOrderFile;

use DateTimeImmutable;

/** A private file of an order or a return, opened: an expiring link to it. */
final readonly class OrderFile
{
    public function __construct(
        public string $mediaId,
        public string $url,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
