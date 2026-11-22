<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

/**
 * One call to the gateway as it is logged (payments.md §1.1 rule 13): never the key, the secret or the
 * `Authorization` header, and the card details already removed.
 */
final readonly class GatewayCall
{
    /**
     * @param  array<array-key, mixed>|null  $request
     * @param  array<array-key, mixed>|null  $response
     */
    public function __construct(
        public string $storeId,
        public ?string $attemptId,
        public string $endpoint,
        public ?array $request,
        public ?int $httpStatus,
        public ?array $response,
        public ?string $error,
        public int $tookMs,
    ) {}
}
