<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Shared\Domain\ValueObject\StoreId;

/**
 * Carries the store's currency details too, so a price can be formatted with one call.
 *
 * `isActive` says whether the store is on (platform.md §1.1, §1.6; owner, 2026-10-01): an off store
 * is as if it were never there, except in history, where it is named as it was. `isBase` marks the
 * base store, which is always on (owner, 2026-10-02) — found by this mark, never by its code.
 */
final readonly class StoreDto
{
    public function __construct(
        public string $id,
        public string $code,
        public TranslatedTextDto $name,
        public string $countryCode,
        public string $currencyCode,
        public int $currencyExponent,
        public ?string $currencySign,
        public TranslatedTextDto $currencyAbbreviation,
        public int $taxRateBasisPoints,
        public string $timezone,
        public int $position,
        public bool $isActive = true,
        public bool $isBase = false,
    ) {}

    public function storeId(): StoreId
    {
        return StoreId::fromString($this->id);
    }

    public function currencySymbol(string $locale): string
    {
        return $this->currencySign ?? $this->currencyAbbreviation->in($locale);
    }
}
