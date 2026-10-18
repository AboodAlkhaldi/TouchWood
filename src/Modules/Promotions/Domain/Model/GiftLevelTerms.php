<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use DateTimeImmutable;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;

/**
 * What staff set on a gift level (promotions.md §1.7, §5), checked as it is built: an amount above 0 in
 * the store's currency, one variant as the gift, its buyers — individuals, companies or both — and
 * optional dates, the end after the start; times in UTC to the second, as they are stored.
 */
final readonly class GiftLevelTerms
{
    private function __construct(
        public int $amountMinor,
        public string $currencyCode,
        public string $variantId,
        public bool $forPublic,
        public bool $forCompany,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}

    /**
     * @throws InvalidPromotionAttribute
     */
    public static function of(int $amountMinor, string $currencyCode, string $variantId, bool $forPublic, bool $forCompany, ?DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt): self
    {
        if ($amountMinor < 1) {
            throw new InvalidPromotionAttribute('amount', 'more than 0');
        }

        $variantId = strtolower(trim($variantId));

        if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}\z/', $variantId) !== 1) {
            throw new InvalidPromotionAttribute('gift_variant', 'not an id');
        }

        if (! $forPublic && ! $forCompany) {
            throw new InvalidPromotionAttribute('buyers', 'individuals, companies or both');
        }

        $startsAt = $startsAt === null ? null : new DateTimeImmutable('@'.$startsAt->getTimestamp());
        $endsAt = $endsAt === null ? null : new DateTimeImmutable('@'.$endsAt->getTimestamp());

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidPromotionAttribute('dates', 'the end after the start');
        }

        return new self($amountMinor, $currencyCode, $variantId, $forPublic, $forCompany, $startsAt, $endsAt);
    }

    /** A stored level's terms, as the database CHECKs already hold them: not checked again. */
    public static function stored(GiftLevel $level): self
    {
        return new self($level->amountMinor, $level->currencyCode, $level->variantId, $level->forPublic, $level->forCompany, $level->startsAt, $level->endsAt);
    }

    /**
     * Whether another level of the store, both active, would give two gifts for one amount (§1.7). A
     * level already ended gives no gift again, and so never clashes; extending it is checked again.
     */
    public function clashesWith(GiftLevel $other, DateTimeImmutable $now): bool
    {
        return ($this->endsAt === null || $this->endsAt > $now)
            && ($other->endsAt === null || $other->endsAt > $now)
            && $other->amountMinor === $this->amountMinor
            && (($this->forPublic && $other->forPublic) || ($this->forCompany && $other->forCompany))
            && ($this->startsAt === null || $other->endsAt === null || $this->startsAt < $other->endsAt)
            && ($other->startsAt === null || $this->endsAt === null || $other->startsAt < $this->endsAt);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function snapshot(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'variant_id' => $this->variantId,
            'for_public' => $this->forPublic,
            'for_company' => $this->forCompany,
            'starts_at' => $this->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
        ];
    }
}
