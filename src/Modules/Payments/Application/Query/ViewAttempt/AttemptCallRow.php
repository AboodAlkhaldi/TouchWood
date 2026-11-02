<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewAttempt;

use DateTimeImmutable;

/** One call to the gateway, as sent and answered: keys, card details and secrets were never stored (rule 13). */
final readonly class AttemptCallRow
{
    /**
     * @param  array<array-key, mixed>|null  $request
     * @param  array<array-key, mixed>|null  $response
     */
    public function __construct(
        public string $id,
        public string $endpoint,
        public ?array $request,
        public ?int $httpStatus,
        public ?array $response,
        public ?string $error,
        public int $tookMs,
        public DateTimeImmutable $at,
    ) {}
}
