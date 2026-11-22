<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Modules\Platform\Public\Dto\ShopPolicyDto;

/**
 * The terms the checkout page showed beside its tick (flags-2026-11.md S3.3; owner, 2026-11-20): the Terms and
 * Conditions' policy and version, and the Returns and Refunds policy's when the sentence named it. Sent back with the
 * tick, so the version recorded is the one the customer was shown: anything else live now is `TermsChanged`. A value
 * the page did not send, or sent malformed, is none.
 */
final readonly class ShownTerms
{
    public function __construct(
        public ?string $termsId = null,
        public ?int $termsVersion = null,
        public ?string $returnsId = null,
        public ?int $returnsVersion = null,
    ) {}

    /** As a request carries them: an id a string, a version a whole number (or its digits); anything else none. */
    public static function of(mixed $termsId, mixed $termsVersion, mixed $returnsId, mixed $returnsVersion): self
    {
        return new self(self::id($termsId), self::version($termsVersion), self::id($returnsId), self::version($returnsVersion));
    }

    /** What a page shows of these policies, null where the store has none. */
    public static function from(?ShopPolicyDto $terms, ?ShopPolicyDto $returns): self
    {
        return new self($terms?->policyId, $terms?->version, $returns?->policyId, $returns?->version);
    }

    /** Whether what the page showed is the store's now: the same policy at the same version, or none and none. */
    public function matches(?ShopPolicyDto $terms, ?ShopPolicyDto $returns): bool
    {
        return self::same($this->termsId, $this->termsVersion, $terms) && self::same($this->returnsId, $this->returnsVersion, $returns);
    }

    private static function same(?string $id, ?int $version, ?ShopPolicyDto $live): bool
    {
        return $live === null
            ? $id === null && $version === null
            : $id !== null && strtolower($id) === strtolower($live->policyId) && $version === $live->version;
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }

    private static function version(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }
}
