<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use DateTimeImmutable;

final readonly class OpenedSession
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $expiresAt,
    ) {}
}
